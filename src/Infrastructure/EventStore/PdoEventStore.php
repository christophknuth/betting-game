<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\EventStore;

use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Event\BetPeriodCreated;
use BettingGame\Domain\Event\BetRowAssigned;
use BettingGame\Domain\Event\BetRowReplaced;
use BettingGame\Domain\Event\DrawRecorded;
use BettingGame\Domain\Event\DrawWinningsRecorded;
use BettingGame\Domain\Event\FeeCharged;
use BettingGame\Domain\Event\FeePaymentRecorded;
use BettingGame\Domain\Event\MemberAdded;
use BettingGame\Domain\Event\ParticipantApproved;
use BettingGame\Domain\Event\ParticipantCreated;
use BettingGame\Domain\Event\PayoutDistributed;
use BettingGame\Domain\Event\TicketSubmitted;
use BettingGame\Domain\Event\TippYearCreated;
use BettingGame\Domain\Event\TippYearStatusChanged;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Repository\RecordedEvent;
use BettingGame\Domain\Exception\ConcurrencyException;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Support\Row;
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

        // A repository wraps the append and its projection write in one
        // transaction, so by the time we get here one is usually already open.
        // Committing it from in here would publish the events while the
        // projection is still unwritten - only manage a transaction we started.
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

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

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Exception $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /** @return list<DomainEvent> */
    public function getStream(string $streamId): array
    {
        // event_store keys on (aggregate_type, aggregate_id); the stream id is a
        // separate identifier that only event_stream knows, so the lookup has to
        // go through it. Matching on aggregate_id alone would both miss the
        // prefix and collide across types - bet_row 1 and draw 1 share an id.
        $rows = $this->db->fetchAll(
            '
            SELECT e.event_type, e.event_data, e.metadata, e.occurred_at
            FROM event_store e
            JOIN event_stream s
                ON s.aggregate_type = e.aggregate_type
               AND s.aggregate_id = e.aggregate_id
            WHERE s.stream_id = ?
            ORDER BY e.version ASC
            ',
            [$streamId]
        );

        $events = [];
        foreach ($rows as $row) {
            $events[] = $this->deserializeEvent($row);
        }

        return $events;
    }

    /** @return list<RecordedEvent> */
    public function recordsOf(string $streamId): array
    {
        $rows = $this->db->fetchAll(
            '
            SELECT e.event_store_id, e.version, e.event_type, e.event_data, e.metadata, e.occurred_at
            FROM event_store e
            JOIN event_stream s
                ON s.aggregate_type = e.aggregate_type
               AND s.aggregate_id = e.aggregate_id
            WHERE s.stream_id = ?
            ORDER BY e.version ASC
            ',
            [$streamId]
        );

        return $this->toRecords($rows);
    }

    /**
     * @param list<string> $eventTypes
     *
     * @return list<RecordedEvent>
     */
    public function readFrom(int $afterPosition, int $limit = 1000, array $eventTypes = []): array
    {
        // Ordering by the auto-increment id is what makes a replay deterministic:
        // occurred_at can tie, and two aggregates' versions say nothing about
        // their order relative to each other.
        if ($eventTypes === []) {
            $rows = $this->db->fetchAll(
                '
                SELECT event_store_id, version, event_type, event_data, metadata, occurred_at
                FROM event_store
                WHERE event_store_id > ?
                ORDER BY event_store_id ASC
                LIMIT ' . max(1, $limit),
                [$afterPosition]
            );

            return $this->toRecords($rows);
        }

        $placeholders = implode(', ', array_fill(0, count($eventTypes), '?'));

        $rows = $this->db->fetchAll(
            '
            SELECT event_store_id, version, event_type, event_data, metadata, occurred_at
            FROM event_store
            WHERE event_store_id > ? AND event_type IN (' . $placeholders . ')
            ORDER BY event_store_id ASC
            LIMIT ' . max(1, $limit),
            [$afterPosition, ...$eventTypes]
        );

        return $this->toRecords($rows);
    }

    public function headPosition(): int
    {
        $row = $this->db->fetchOne('SELECT COALESCE(MAX(event_store_id), 0) AS head FROM event_store');

        return $row === null ? 0 : Row::int($row, 'head');
    }

    /** @param list<string> $eventTypes */
    public function countFrom(int $afterPosition, array $eventTypes = []): int
    {
        if ($eventTypes === []) {
            $row = $this->db->fetchOne(
                'SELECT COUNT(*) AS pending FROM event_store WHERE event_store_id > ?',
                [$afterPosition]
            );

            return $row === null ? 0 : Row::int($row, 'pending');
        }

        $placeholders = implode(', ', array_fill(0, count($eventTypes), '?'));

        $row = $this->db->fetchOne(
            '
            SELECT COUNT(*) AS pending
            FROM event_store
            WHERE event_store_id > ? AND event_type IN (' . $placeholders . ')
            ',
            [$afterPosition, ...$eventTypes]
        );

        return $row === null ? 0 : Row::int($row, 'pending');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<RecordedEvent>
     */
    private function toRecords(array $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
            $records[] = new RecordedEvent(
                Row::int($row, 'event_store_id'),
                Row::int($row, 'version'),
                $this->deserializeEvent($row)
            );
        }

        return $records;
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
            'bet_period.created' => new BetPeriodCreated(
                Row::string($eventData, 'bet_period_id'),
                Row::int($eventData, 'tipp_year_id'),
                Row::string($eventData, 'name'),
                Row::string($eventData, 'start_date'),
                Row::string($eventData, 'end_date'),
                Row::nullableInt($eventData, 'sequence') ?? 1,
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            'bet_row.assigned' => new BetRowAssigned(
                Row::string($eventData, 'bet_row_id'),
                Row::int($eventData, 'participant_id'),
                Row::int($eventData, 'bet_period_id'),
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
                self::objectList($eventData, 'winning_classes'),
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
                // Nullable: events written before the price list existed carry
                // no rates, and the log is immutable.
                Row::nullableFloat($eventData, 'processing_fee_single_week') ?? 0.0,
                Row::nullableFloat($eventData, 'processing_fee_multi_week') ?? 0.0,
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
            'tipp_year.payout_distributed' => new PayoutDistributed(
                Row::string($eventData, 'tipp_year_id'),
                Row::float($eventData, 'total_winnings'),
                Row::int($eventData, 'participant_count'),
                Row::float($eventData, 'share_per_participant'),
                self::objectList($eventData, 'shares'),
                Row::nullableString($eventData, 'booked_by'),
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            'ticket.submitted' => new TicketSubmitted(
                Row::string($eventData, 'ticket_id'),
                Row::int($eventData, 'tipp_year_id'),
                Row::string($eventData, 'period_start'),
                Row::string($eventData, 'period_end'),
                // Absent from every ticket written before the Laufzeit was
                // asked for. The event log is immutable, so reading them as
                // required would break a rebuild on all of them.
                Row::nullableInt($eventData, 'duration_weeks'),
                Row::nullableString($eventData, 'draw_days'),
                Row::int($eventData, 'draw_count'),
                Row::float($eventData, 'total_cost'),
                self::objectList($eventData, 'rows'),
                Row::nullableInt($eventData, 'superzahl'),
                Row::nullableString($eventData, 'lottery_reference'),
                Row::nullableFloat($eventData, 'processing_fee') ?? 0.0,
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            'fee.charged' => new FeeCharged(
                Row::string($eventData, 'fee_id'),
                Row::int($eventData, 'participant_id'),
                Row::int($eventData, 'ticket_id'),
                Row::float($eventData, 'amount'),
                Row::string($eventData, 'due_date'),
                $domainEventId,
                $occurredAt,
                $causationId,
                $correlationId
            ),
            'fee.payment_recorded' => new FeePaymentRecorded(
                Row::string($eventData, 'fee_id'),
                Row::int($eventData, 'participant_id'),
                Row::string($eventData, 'payment_status'),
                Row::nullableString($eventData, 'paid_at'),
                Row::nullableString($eventData, 'payment_method'),
                Row::nullableString($eventData, 'booked_by'),
                Row::nullableString($eventData, 'note'),
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
                Row::nullableInt($eventData, 'user_id'),
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

    /**
     * A JSON array of objects - the ticket's rows, the payout's shares.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private static function objectList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            throw new \RuntimeException("Field $key is not a list of objects");
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException("Field $key contains a non-object");
            }

            /** @var array<string, mixed> $item */
            $items[] = $item;
        }

        return $items;
    }
}
