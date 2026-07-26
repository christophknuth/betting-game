<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\EventStore;

use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Event\PredictionSubmitted;
use BettingGame\Domain\Event\PredictionUpdated;
use BettingGame\Domain\Event\PredictionEvaluated;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Exception\ConcurrencyException;
use PDO;

final class PdoEventStore implements EventStoreInterface
{
    public function __construct(
        private PDO $pdo
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function append(string $streamId, array $events, int $expectedVersion): void
    {
        if (empty($events)) {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            // Check stream version for optimistic locking
            $currentVersion = $this->getStreamVersion($streamId);
            
            if ($currentVersion !== $expectedVersion) {
                throw new ConcurrencyException(
                    "Stream version mismatch. Expected $expectedVersion, got $currentVersion"
                );
            }

            $stmt = $this->pdo->prepare('
                INSERT INTO event_store (
                    aggregate_type, aggregate_id, version, event_type,
                    event_data, metadata, occurred_at, causation_id, correlation_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            $version = $currentVersion;
            foreach ($events as $event) {
                if (!$event instanceof DomainEvent) {
                    throw new \InvalidArgumentException('Invalid event type');
                }

                $version++;

                $stmt->execute([
                    $event->aggregateType(),
                    $event->aggregateId(),
                    $version,
                    $event->eventType(),
                    json_encode($event->toArray()),
                    json_encode($event->metadata()),
                    $event->occurredAt()->format('Y-m-d H:i:s.u'),
                    $event->causationId(),
                    $event->correlationId(),
                ]);
            }

            // Update or create stream record
            $this->updateStreamVersion($streamId, $events[0]->aggregateType(), $events[0]->aggregateId(), $version);

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getStream(string $streamId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT event_type, event_data, metadata, occurred_at
            FROM event_store
            WHERE aggregate_id = ?
            ORDER BY version ASC
        ');

        $stmt->execute([$streamId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $events = [];
        foreach ($rows as $row) {
            $events[] = $this->deserializeEvent($row);
        }

        return $events;
    }

    public function getStreamVersion(string $streamId): int
    {
        $stmt = $this->pdo->prepare('
            SELECT current_version FROM event_stream WHERE stream_id = ?
        ');

        $stmt->execute([$streamId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int) $result['current_version'] : 0;
    }

    private function updateStreamVersion(string $streamId, string $aggregateType, string $aggregateId, int $version): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO event_stream (stream_id, aggregate_type, aggregate_id, current_version, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                current_version = ?, 
                updated_at = NOW()
        ');

        $stmt->execute([$streamId, $aggregateType, $aggregateId, $version, $version]);
    }

    private function deserializeEvent(array $row): DomainEvent
    {
        $eventData = json_decode($row['event_data'], true);
        $metadata = json_decode($row['metadata'], true);
        $occurredAt = new \DateTimeImmutable($row['occurred_at']);

        return match ($row['event_type']) {
            'prediction.submitted' => new PredictionSubmitted(
                $eventData['prediction_id'],
                $eventData['participant_id'],
                $eventData['event_id'],
                $eventData['prediction_data'],
                $metadata['event_id'],
                $occurredAt,
                $metadata['causation_id'],
                $metadata['correlation_id']
            ),
            'prediction.updated' => new PredictionUpdated(
                $eventData['prediction_id'],
                $eventData['prediction_data'],
                $eventData['version'],
                $metadata['event_id'],
                $occurredAt,
                $metadata['causation_id'],
                $metadata['correlation_id']
            ),
            'prediction.evaluated' => new PredictionEvaluated(
                $eventData['prediction_id'],
                $eventData['points_earned'],
                $eventData['prize_amount'],
                $metadata['event_id'],
                $occurredAt,
                $metadata['causation_id'],
                $metadata['correlation_id']
            ),
            default => throw new \RuntimeException('Unknown event type: ' . $row['event_type'])
        };
    }
}
