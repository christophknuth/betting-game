<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Support\Row;
use BettingGame\Domain\Event\MemberAdded;
use BettingGame\Domain\Event\PayoutDistributed;
use BettingGame\Domain\Model\TippYear;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Domain\ValueObject\DateRange;
use BettingGame\Domain\ValueObject\TippYearStatus;
use DateTimeImmutable;

/**
 * The tipp year and everything its own stream projects.
 *
 * Membership and the annual distribution are not separate aggregates - they are
 * decisions recorded against the year (`MemberAdded`, `PayoutDistributed`), so
 * their tables are projections of this stream and are written here.
 */
final class TippYearRepository extends EventSourcedRepository implements TippYearRepositoryInterface
{
    private const STREAM_PREFIX = 'tipp_year-';

    public function find(int $id): ?TippYear
    {
        $row = $this->db->fetchOne('SELECT * FROM tipp_year WHERE tipp_year_id = ?', [$id]);

        return $row === null ? null : $this->toAggregate($row);
    }

    public function save(TippYear $tippYear): void
    {
        $exists = $tippYear->isPersisted();

        $version = $this->transactionally(function () use ($tippYear, $exists): int {
            $events = $tippYear->releaseEvents();
            $version = $this->append(
                self::STREAM_PREFIX . $tippYear->id(),
                $events,
                $tippYear->originalVersion()
            );

            $this->writeProjection($tippYear, $version, $exists);

            foreach ($events as $event) {
                if ($event instanceof MemberAdded) {
                    $this->projectMembership($event);
                }

                if ($event instanceof PayoutDistributed) {
                    $this->projectPayout($event);
                }
            }

            return $version;
        });

        $tippYear->markCommitted($version);
    }

    public function nextIdentity(): int
    {
        return $this->nextId('tipp_year', 'tipp_year_id');
    }

    public function findCovering(DateTimeImmutable $date): ?TippYear
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM tipp_year WHERE ? BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1',
            [$date->format('Y-m-d')]
        );

        return $row === null ? null : $this->toAggregate($row);
    }

    public function findRunning(): ?TippYear
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM tipp_year WHERE status = 'running' ORDER BY start_date DESC LIMIT 1"
        );

        return $row === null ? null : $this->toAggregate($row);
    }

    /** @return list<DateRange> */
    public function existingRanges(?int $excludeId = null): array
    {
        $rows = $this->db->fetchAll(
            'SELECT start_date, end_date FROM tipp_year WHERE (? IS NULL OR tipp_year_id <> ?) ORDER BY start_date',
            [$excludeId, $excludeId]
        );

        return array_map(
            static fn (array $row): DateRange => DateRange::fromStrings(
                Row::string($row, 'start_date'),
                Row::string($row, 'end_date')
            ),
            $rows
        );
    }

    /**
     * Correlated subqueries rather than joins: joining membership, ticket and
     * draw at once would multiply the rows and make every count wrong.
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $status = null): array
    {
        return $this->db->fetchAll(
            "
            SELECT
                y.*,
                (SELECT COUNT(*) FROM membership m
                  WHERE m.tipp_year_id = y.tipp_year_id AND m.status = 'active') AS member_count,
                (SELECT COUNT(*) FROM ticket t
                  WHERE t.tipp_year_id = y.tipp_year_id) AS ticket_count,
                (SELECT COUNT(*) FROM draw d
                  WHERE d.tipp_year_id = y.tipp_year_id) AS draw_count,
                (SELECT COALESCE(SUM(r.total_amount), 0)
                   FROM ticket_draw_result r
                   JOIN draw d2 ON d2.draw_id = r.draw_id
                  WHERE d2.tipp_year_id = y.tipp_year_id) AS total_winnings
            FROM tipp_year y
            WHERE (? IS NULL OR y.status = ?)
            ORDER BY y.start_date DESC
            ",
            [$status, $status]
        );
    }

    /** @return list<int> */
    public function memberIds(int $tippYearId): array
    {
        $rows = $this->db->fetchAll(
            "
            SELECT participant_id
            FROM membership
            WHERE tipp_year_id = ? AND status = 'active'
            ORDER BY participant_id
            ",
            [$tippYearId]
        );

        return array_map(static fn (array $row): int => Row::int($row, 'participant_id'), $rows);
    }

    public function isMember(int $tippYearId, int $participantId): bool
    {
        $row = $this->db->fetchOne(
            "
            SELECT COUNT(*) AS cnt
            FROM membership
            WHERE tipp_year_id = ? AND participant_id = ? AND status = 'active'
            ",
            [$tippYearId, $participantId]
        );

        return $row !== null && Row::int($row, 'cnt') > 0;
    }

    /** @return list<array<string, mixed>> */
    public function membershipsOf(int $participantId): array
    {
        return $this->db->fetchAll(
            '
            SELECT
                m.membership_id, m.tipp_year_id, m.joined_at, m.left_at, m.status,
                y.name AS tipp_year_name, y.start_date, y.end_date, y.status AS tipp_year_status
            FROM membership m
            JOIN tipp_year y ON y.tipp_year_id = m.tipp_year_id
            WHERE m.participant_id = ?
            ORDER BY y.start_date DESC
            ',
            [$participantId]
        );
    }

    /** @return array<string, mixed>|null */
    public function payoutShareOf(int $tippYearId, int $participantId): ?array
    {
        return $this->db->fetchOne(
            '
            SELECT
                s.payout_share_id, s.amount, s.payment_status, s.paid_at,
                p.total_winnings, p.participant_count, p.share_per_participant, p.distributed_at
            FROM payout_share s
            JOIN payout p ON p.payout_id = s.payout_id
            WHERE p.tipp_year_id = ? AND s.participant_id = ?
            ',
            [$tippYearId, $participantId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findPayout(int $tippYearId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM payout WHERE tipp_year_id = ?', [$tippYearId]);
    }

    private function writeProjection(TippYear $tippYear, int $version, bool $exists): void
    {
        if ($exists) {
            $this->db->execute(
                '
                UPDATE tipp_year
                SET name = ?, start_date = ?, end_date = ?, status = ?,
                    ticket_cost_per_row = ?, version = ?
                WHERE tipp_year_id = ?
                ',
                [
                    $tippYear->name(),
                    $tippYear->startDate()->format('Y-m-d'),
                    $tippYear->endDate()->format('Y-m-d'),
                    $tippYear->status()->value(),
                    $tippYear->ticketCostPerRow(),
                    $version,
                    $tippYear->id(),
                ]
            );

            return;
        }

        $this->db->execute(
            '
            INSERT INTO tipp_year (
                tipp_year_id, name, start_date, end_date, status,
                ticket_cost_per_row, created_at, version
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ',
            [
                $tippYear->id(),
                $tippYear->name(),
                $tippYear->startDate()->format('Y-m-d'),
                $tippYear->endDate()->format('Y-m-d'),
                $tippYear->status()->value(),
                $tippYear->ticketCostPerRow(),
                $tippYear->createdAt()->format('Y-m-d H:i:s'),
                $version,
            ]
        );
    }

    private function projectMembership(MemberAdded $event): void
    {
        $payload = $event->toArray();

        $this->db->execute(
            "
            INSERT INTO membership (participant_id, tipp_year_id, joined_at, status)
            VALUES (?, ?, ?, 'active')
            ON DUPLICATE KEY UPDATE
                status = 'active',
                left_at = NULL
            ",
            [
                Row::int($payload, 'participant_id'),
                Row::string($payload, 'tipp_year_id'),
                (new DateTimeImmutable(Row::string($payload, 'joined_at')))->format('Y-m-d H:i:s'),
            ]
        );
    }

    private function projectPayout(PayoutDistributed $event): void
    {
        $payload = $event->toArray();
        $tippYearId = Row::string($payload, 'tipp_year_id');

        $this->db->execute(
            '
            INSERT INTO payout (
                tipp_year_id, total_winnings, participant_count,
                share_per_participant, distributed_at, booked_by
            ) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_winnings = VALUES(total_winnings),
                participant_count = VALUES(participant_count),
                share_per_participant = VALUES(share_per_participant),
                booked_by = VALUES(booked_by)
            ',
            [
                $tippYearId,
                Row::float($payload, 'total_winnings'),
                Row::int($payload, 'participant_count'),
                Row::float($payload, 'share_per_participant'),
                $event->occurredAt()->format('Y-m-d H:i:s'),
                Row::nullableString($payload, 'booked_by'),
            ]
        );

        $payout = $this->db->fetchOne('SELECT payout_id FROM payout WHERE tipp_year_id = ?', [$tippYearId]);

        if ($payout === null) {
            return;
        }

        $payoutId = Row::int($payout, 'payout_id');
        $shares = $payload['shares'] ?? [];

        if (!is_array($shares)) {
            return;
        }

        foreach ($shares as $share) {
            if (!is_array($share)) {
                continue;
            }

            /** @var array<string, mixed> $share */
            $this->db->execute(
                "
                INSERT INTO payout_share (payout_id, participant_id, amount, payment_status)
                VALUES (?, ?, ?, 'open')
                ON DUPLICATE KEY UPDATE amount = VALUES(amount)
                ",
                [$payoutId, Row::int($share, 'participant_id'), Row::float($share, 'amount')]
            );
        }
    }

    /** @param array<string, mixed> $row */
    private function toAggregate(array $row): TippYear
    {
        return TippYear::fromProjection(
            id: Row::int($row, 'tipp_year_id'),
            name: Row::string($row, 'name'),
            startDate: new DateTimeImmutable(Row::string($row, 'start_date')),
            endDate: new DateTimeImmutable(Row::string($row, 'end_date')),
            status: new TippYearStatus(Row::string($row, 'status')),
            ticketCostPerRow: Row::float($row, 'ticket_cost_per_row'),
            createdAt: new DateTimeImmutable(Row::string($row, 'created_at')),
            version: Row::int($row, 'version')
        );
    }
}
