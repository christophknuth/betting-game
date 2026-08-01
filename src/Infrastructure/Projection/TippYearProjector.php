<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Projection;

use BettingGame\Application\Projection\Projector;
use BettingGame\Domain\Repository\RecordedEvent;
use BettingGame\Domain\ValueObject\TippYearStatus;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Support\Row;
use DateTimeImmutable;

/**
 * The tipp year and the read models its own stream feeds: membership and the
 * annual distribution. They belong to this projector for the same reason they
 * belong to TippYearRepository - both are decisions recorded against the year,
 * not aggregates of their own.
 */
final class TippYearProjector implements Projector
{
    public const NAME = 'tipp_year_read_model';

    public const EVENT_CREATED = 'tipp_year.created';

    public const EVENT_STATUS_CHANGED = 'tipp_year.status_changed';

    public const EVENT_MEMBER_ADDED = 'tipp_year.member_added';

    public const EVENT_PAYOUT_DISTRIBUTED = 'tipp_year.payout_distributed';

    public function __construct(private Db $db)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return [
            self::EVENT_CREATED,
            self::EVENT_STATUS_CHANGED,
            self::EVENT_MEMBER_ADDED,
            self::EVENT_PAYOUT_DISTRIBUTED,
        ];
    }

    public function reset(): void
    {
        // Children first - payout_share and membership hang off these by
        // foreign key, and payout_share also references participant.
        $this->db->execute('DELETE FROM payout_share');
        $this->db->execute('DELETE FROM payout');
        $this->db->execute('DELETE FROM membership');
        $this->db->execute('DELETE FROM tipp_year');

        // DELETE keeps the auto-increment counter, so a rebuild would renumber
        // these rows. Resetting it makes the rebuilt model comparable.
        $this->db->execute('ALTER TABLE payout_share AUTO_INCREMENT = 1');
        $this->db->execute('ALTER TABLE payout AUTO_INCREMENT = 1');
        $this->db->execute('ALTER TABLE membership AUTO_INCREMENT = 1');
    }

    public function apply(RecordedEvent $record): void
    {
        $data = $record->event->toArray();

        match ($record->event->eventType()) {
            self::EVENT_CREATED => $this->created($data, $record),
            self::EVENT_STATUS_CHANGED => $this->db->execute(
                'UPDATE tipp_year SET status = ?, version = ? WHERE tipp_year_id = ?',
                [
                    Row::string($data, 'to_status'),
                    $record->version,
                    Row::int($data, 'tipp_year_id'),
                ]
            ),
            self::EVENT_MEMBER_ADDED => $this->memberAdded($data, $record),
            self::EVENT_PAYOUT_DISTRIBUTED => $this->distributed($data, $record),
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function created(array $data, RecordedEvent $record): void
    {
        $this->db->execute(
            '
            INSERT INTO tipp_year (
                tipp_year_id, name, start_date, end_date, status,
                ticket_cost_per_row, processing_fee_single_week,
                processing_fee_multi_week, created_at, version
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ',
            [
                Row::int($data, 'tipp_year_id'),
                Row::string($data, 'name'),
                Row::string($data, 'start_date'),
                Row::string($data, 'end_date'),
                TippYearStatus::PLANNED,
                Row::float($data, 'ticket_cost_per_row'),
                // Nullable, not required: tipp years created before the price
                // list existed carry no rates, and the event log is immutable.
                // Demanding them would break a rebuild on every earlier event.
                Row::nullableFloat($data, 'processing_fee_single_week') ?? 0.0,
                Row::nullableFloat($data, 'processing_fee_multi_week') ?? 0.0,
                $record->event->occurredAt()->format('Y-m-d H:i:s'),
                $record->version,
            ]
        );
    }

    /** @param array<string, mixed> $data */
    private function memberAdded(array $data, RecordedEvent $record): void
    {
        $tippYearId = Row::int($data, 'tipp_year_id');

        $this->db->execute(
            "
            INSERT INTO membership (participant_id, tipp_year_id, joined_at, status)
            VALUES (?, ?, ?, 'active')
            ON DUPLICATE KEY UPDATE status = 'active', left_at = NULL
            ",
            [
                Row::int($data, 'participant_id'),
                $tippYearId,
                (new DateTimeImmutable(Row::string($data, 'joined_at')))->format('Y-m-d H:i:s'),
            ]
        );

        $this->db->execute(
            'UPDATE tipp_year SET version = ? WHERE tipp_year_id = ?',
            [$record->version, $tippYearId]
        );
    }

    /** @param array<string, mixed> $data */
    private function distributed(array $data, RecordedEvent $record): void
    {
        $tippYearId = Row::int($data, 'tipp_year_id');

        $this->db->execute(
            '
            INSERT INTO payout (
                tipp_year_id, total_winnings, participant_count,
                share_per_participant, distributed_at, booked_by
            ) VALUES (?, ?, ?, ?, ?, ?)
            ',
            [
                $tippYearId,
                Row::float($data, 'total_winnings'),
                Row::int($data, 'participant_count'),
                Row::float($data, 'share_per_participant'),
                $record->event->occurredAt()->format('Y-m-d H:i:s'),
                Row::nullableString($data, 'booked_by'),
            ]
        );

        $payout = $this->db->fetchOne('SELECT payout_id FROM payout WHERE tipp_year_id = ?', [$tippYearId]);

        if ($payout !== null) {
            $shares = $data['shares'] ?? [];

            if (is_array($shares)) {
                foreach ($shares as $share) {
                    if (!is_array($share)) {
                        continue;
                    }

                    /** @var array<string, mixed> $share */
                    $this->db->execute(
                        "
                        INSERT INTO payout_share (payout_id, participant_id, amount, payment_status)
                        VALUES (?, ?, ?, 'open')
                        ",
                        [
                            Row::int($payout, 'payout_id'),
                            Row::int($share, 'participant_id'),
                            Row::float($share, 'amount'),
                        ]
                    );
                }
            }
        }

        $this->db->execute(
            'UPDATE tipp_year SET status = ?, version = ? WHERE tipp_year_id = ?',
            [TippYearStatus::DISTRIBUTED, $record->version, $tippYearId]
        );
    }
}
