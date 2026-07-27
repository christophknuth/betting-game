<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\EventStore;

use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Event\PredictionSubmitted;
use BettingGame\Domain\Event\PredictionUpdated;
use BettingGame\Domain\Event\PredictionEvaluated;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Exception\ConcurrencyException;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Infrastructure\Persistence\Row;
use PDO;

final class PdoEventStore implements EventStoreInterface
{
    public function __construct(
        private Db $db
    ) {
        $this->db->pdo()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @param list<DomainEvent> $events */
    public function append(string $streamId, array $events, int $expectedVersion): void
    {
        if ($events === []) {
            return;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            // Check stream version for optimistic locking
            $currentVersion = $this->getStreamVersion($streamId);

            if ($currentVersion !== $expectedVersion) {
                throw new ConcurrencyException(
                    "Stream version mismatch. Expected $expectedVersion, got $currentVersion"
                );
            }

            $version = $currentVersion;
            foreach ($events as $event) {
                $version++;

                $this->db->execute(
                    '
                    INSERT INTO event_store (
                        aggregate_type, aggregate_id, version, event_type,
                        event_data, metadata, occurred_at, causation_id, correlation_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ',
                    [
                        $event->aggregateType(),
                        $event->aggregateId(),
                        $version,
                        $event->eventType(),
                        json_encode($event->toArray(), JSON_THROW_ON_ERROR),
                        json_encode($event->metadata(), JSON_THROW_ON_ERROR),
                        $event->occurredAt()->format('Y-m-d H:i:s.u'),
                        $event->causationId(),
                        $event->correlationId(),
                    ]
                );
            }

            // Update or create stream record
            $this->updateStreamVersion($streamId, $events[0]->aggregateType(), $events[0]->aggregateId(), $version);

            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<DomainEvent> */
    public function getStream(string $streamId): array
    {
        $rows = $this->db->fetchAll(
            '
            SELECT event_type, event_data, metadata, occurred_at
            FROM event_store
            WHERE aggregate_id = ?
            ORDER BY version ASC
            ',
            [$streamId]
        );

        $events = [];
        foreach ($rows as $row) {
            $events[] = $this->deserializeEvent($row);
        }

        return $events;
    }

    public function getStreamVersion(string $streamId): int
    {
        $row = $this->db->fetchOne(
            'SELECT current_version FROM event_stream WHERE stream_id = ?',
            [$streamId]
        );

        return $row !== null ? Row::int($row, 'current_version') : 0;
    }

    private function updateStreamVersion(string $streamId, string $aggregateType, string $aggregateId, int $version): void
    {
        $this->db->execute(
            '
            INSERT INTO event_stream (stream_id, aggregate_type, aggregate_id, current_version, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                current_version = ?,
                updated_at = NOW()
            ',
            [$streamId, $aggregateType, $aggregateId, $version, $version]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function deserializeEvent(array $row): DomainEvent
    {
        $eventData = Row::json($row, 'event_data');
        $metadata = Row::json($row, 'metadata');
        $occurredAt = new \DateTimeImmutable(Row::string($row, 'occurred_at'));

        $eventType = Row::string($row, 'event_type');

        return match ($eventType) {
            'prediction.submitted' => new PredictionSubmitted(
                Row::string($eventData, 'prediction_id'),
                Row::int($eventData, 'participant_id'),
                Row::int($eventData, 'event_id'),
                Row::json($eventData, 'prediction_data'),
                Row::nullableString($metadata, 'event_id'),
                $occurredAt,
                Row::nullableString($metadata, 'causation_id'),
                Row::nullableString($metadata, 'correlation_id')
            ),
            'prediction.updated' => new PredictionUpdated(
                Row::string($eventData, 'prediction_id'),
                Row::json($eventData, 'prediction_data'),
                Row::int($eventData, 'version'),
                Row::nullableString($metadata, 'event_id'),
                $occurredAt,
                Row::nullableString($metadata, 'causation_id'),
                Row::nullableString($metadata, 'correlation_id')
            ),
            'prediction.evaluated' => new PredictionEvaluated(
                Row::string($eventData, 'prediction_id'),
                Row::int($eventData, 'points_earned'),
                Row::nullableFloat($eventData, 'prize_amount'),
                Row::nullableString($metadata, 'event_id'),
                $occurredAt,
                Row::nullableString($metadata, 'causation_id'),
                Row::nullableString($metadata, 'correlation_id')
            ),
            default => throw new \RuntimeException('Unknown event type: ' . $eventType)
        };
    }
}
