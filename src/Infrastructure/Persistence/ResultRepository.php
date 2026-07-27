<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Model\Result;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Repository\ResultRepositoryInterface;
use DateTimeImmutable;

final class ResultRepository implements ResultRepositoryInterface
{
    private const STREAM_PREFIX = 'result-';

    public function __construct(
        private Db $db,
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

        $this->db->execute(
            '
            INSERT INTO result (result_id, event_id, result_data, recorded_at, updated_at, source)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                result_data = VALUES(result_data),
                updated_at = VALUES(updated_at),
                source = VALUES(source)
            ',
            [
                $result->id(),
                $result->eventId(),
                json_encode($result->resultData(), JSON_THROW_ON_ERROR),
                $result->recordedAt()->format('Y-m-d H:i:s'),
                $result->updatedAt()?->format('Y-m-d H:i:s'),
                $result->source(),
            ]
        );
    }

    public function findById(int $id): ?Result
    {
        return $this->hydrate($this->db->fetchOne('SELECT * FROM result WHERE result_id = ?', [$id]));
    }

    public function findByEventId(int $eventId): ?Result
    {
        return $this->hydrate($this->db->fetchOne('SELECT * FROM result WHERE event_id = ?', [$eventId]));
    }

    public function nextIdentity(): int
    {
        $row = $this->db->fetchOne('SELECT COALESCE(MAX(result_id), 0) + 1 AS next_id FROM result');

        return $row !== null ? Row::int($row, 'next_id') : 1;
    }

    /** @param array<string, mixed>|null $row */
    private function hydrate(?array $row): ?Result
    {
        if ($row === null) {
            return null;
        }

        $updatedAt = Row::nullableString($row, 'updated_at');

        return Result::reconstitute(
            id: Row::int($row, 'result_id'),
            eventId: Row::int($row, 'event_id'),
            resultData: Row::json($row, 'result_data'),
            source: Row::nullableString($row, 'source'),
            recordedAt: new DateTimeImmutable(Row::string($row, 'recorded_at')),
            updatedAt: $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null
        );
    }
}
