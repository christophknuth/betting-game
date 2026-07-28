<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\EventStore;

use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Event\BetRowAssigned;
use BettingGame\Domain\Event\BetRowReplaced;
use BettingGame\Domain\Event\DrawRecorded;
use BettingGame\Domain\Event\DrawWinningsRecorded;
use BettingGame\Domain\Event\MemberAdded;
use BettingGame\Domain\Event\ParticipantApproved;
use BettingGame\Domain\Event\ParticipantCreated;
use BettingGame\Domain\Event\TippYearCreated;
use BettingGame\Domain\Event\TippYearStatusChanged;
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

    private function updateStreamVersion(
        string $streamId,
        string $aggregateType,
        string $aggregateId,
        int $version
    ): void {
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

        $domainEventId = Row::nullableString($metadata, 'event_id');
        $causationId = Row::nullableString($metadata, 'causation_id');
        $correlationId = Row::nullableString($metadata, 'correlation_id');

        return match ($eventType) {
            'bet_row.assigned' => new BetRowAssigned(
                Row::string($eventData, 'bet_row_id'),
                Row::int($eventData, 'participant_id'),
                Row::int($eventData, 'tipp_year_id'),
                self::intList($eventData, 'numbers'),
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            'bet_row.replaced' => new BetRowReplaced(
                Row::string($eventData, 'bet_row_id'),
                self::intList($eventData, 'previous_numbers'),
                self::intList($eventData, 'numbers'),
                Row::string($eventData, 'reason'),
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            'draw.recorded' => new DrawRecorded(
                Row::string($eventData, 'draw_id'),
                Row::int($eventData, 'tipp_year_id'),
                Row::string($eventData, 'draw_date'),
                self::intList($eventData, 'numbers'),
                Row::int($eventData, 'superzahl'),
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            'draw.winnings_recorded' => new DrawWinningsRecorded(
                Row::string($eventData, 'draw_id'),
                Row::int($eventData, 'ticket_id'),
                Row::float($eventData, 'total_amount'),
                Row::json($eventData, 'winning_classes'),
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            'tipp_year.created' => new TippYearCreated(
                Row::string($eventData, 'tipp_year_id'),
                Row::string($eventData, 'name'),
                Row::string($eventData, 'start_date'),
                Row::string($eventData, 'end_date'),
                Row::float($eventData, 'ticket_cost_per_row'),
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            'tipp_year.status_changed' => new TippYearStatusChanged(
                Row::string($eventData, 'tipp_year_id'),
                Row::string($eventData, 'from_status'),
                Row::string($eventData, 'to_status'),
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            'tipp_year.member_added' => new MemberAdded(
                Row::string($eventData, 'tipp_year_id'),
                Row::int($eventData, 'participant_id'),
                Row::string($eventData, 'joined_at'),
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            'participant.created' => new ParticipantCreated(
                Row::string($eventData, 'participant_id'),
                Row::int($eventData, 'user_id'),
                Row::string($eventData, 'display_name'),
                Row::bool($eventData, 'auto_approved'),
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            'participant.approved' => new ParticipantApproved(
                Row::string($eventData, 'participant_id'),
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            default => throw new \RuntimeException('Unknown event type: ' . $eventType)
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<int>
     */
    private static function intList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            throw new \RuntimeException("Field $key is not a list of integers");
        }

        $numbers = [];
        foreach ($value as $item) {
            if (!is_int($item)) {
                throw new \RuntimeException("Field $key contains a non-integer");
            }

            $numbers[] = $item;
        }

        return $numbers;
    }
}
