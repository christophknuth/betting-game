<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Model\Result;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Repository\ResultRepositoryInterface;
use DateTimeImmutable;
use PDO;

final class ResultRepository implements ResultRepositoryInterface
{
    private const STREAM_PREFIX = 'result-';

    public function __construct(
        private PDO $pdo,
        private EventStoreInterface $eventStore
    ) {
    }

    public function save(Result $result): void
    {
        $events = $result->releaseEvents();

        if ($events !== []) {
            $streamId = self::STREAM_PREFIX . $result->id();

            // The result table carries no version column, so the stream version is
            // the only source of truth here - concurrent updates are last-write-wins.
            $this->eventStore->append(
                $streamId,
                $events,
                $this->eventStore->getStreamVersion($streamId)
            );
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO result (result_id, event_id, result_data, recorded_at, updated_at, source)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                result_data = VALUES(result_data),
                updated_at = VALUES(updated_at),
                source = VALUES(source)
        ');

        $stmt->execute([
            $result->id(),
            $result->eventId(),
            json_encode($result->resultData(), JSON_THROW_ON_ERROR),
            $result->recordedAt()->format('Y-m-d H:i:s'),
            $result->updatedAt()?->format('Y-m-d H:i:s'),
            $result->source(),
        ]);
    }

    public function findById(int $id): ?Result
    {
        $stmt = $this->pdo->prepare('SELECT * FROM result WHERE result_id = ?');
        $stmt->execute([$id]);

        return $this->hydrate($stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function findByEventId(int $eventId): ?Result
    {
        $stmt = $this->pdo->prepare('SELECT * FROM result WHERE event_id = ?');
        $stmt->execute([$eventId]);

        return $this->hydrate($stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function nextIdentity(): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(result_id), 0) + 1 AS next_id FROM result');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) $result['next_id'];
    }

    private function hydrate(array|false $row): ?Result
    {
        if ($row === false) {
            return null;
        }

        return Result::reconstitute(
            id: (int) $row['result_id'],
            eventId: (int) $row['event_id'],
            resultData: json_decode($row['result_data'], true, 512, JSON_THROW_ON_ERROR),
            source: $row['source'],
            recordedAt: new DateTimeImmutable($row['recorded_at']),
            updatedAt: $row['updated_at'] !== null ? new DateTimeImmutable($row['updated_at']) : null
        );
    }
}
