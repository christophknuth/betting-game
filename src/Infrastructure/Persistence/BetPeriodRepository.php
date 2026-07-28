<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Model\BetPeriod;
use BettingGame\Domain\Repository\BetPeriodRepositoryInterface;
use BettingGame\Domain\ValueObject\DateRange;
use DateTimeImmutable;

final class BetPeriodRepository extends EventSourcedRepository implements BetPeriodRepositoryInterface
{
    private const STREAM_PREFIX = 'bet_period-';

    public function find(int $id): ?BetPeriod
    {
        $row = $this->db->fetchOne('SELECT * FROM bet_period WHERE bet_period_id = ?', [$id]);

        return $row === null ? null : $this->toAggregate($row);
    }

    public function save(BetPeriod $period): void
    {
        $exists = $period->isPersisted();

        $version = $this->transactionally(function () use ($period, $exists): int {
            $version = $this->append(
                self::STREAM_PREFIX . $period->id(),
                $period->releaseEvents(),
                $period->originalVersion()
            );

            $start = $period->range()->start()->format('Y-m-d');
            $end = $period->range()->end()->format('Y-m-d');

            if ($exists) {
                $this->db->execute(
                    '
                    UPDATE bet_period
                    SET name = ?, start_date = ?, end_date = ?, sequence = ?, version = ?
                    WHERE bet_period_id = ?
                    ',
                    [$period->name(), $start, $end, $period->sequence(), $version, $period->id()]
                );
            } else {
                // uk_year_start must reject a second period starting the same day
                $this->db->execute(
                    '
                    INSERT INTO bet_period (
                        bet_period_id, tipp_year_id, name, start_date, end_date, sequence, version
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ',
                    [
                        $period->id(),
                        $period->tippYearId(),
                        $period->name(),
                        $start,
                        $end,
                        $period->sequence(),
                        $version,
                    ]
                );
            }

            return $version;
        });

        $period->markCommitted($version);
    }

    public function nextIdentity(): int
    {
        return $this->nextId('bet_period', 'bet_period_id');
    }

    /** @return list<BetPeriod> */
    public function findByTippYear(int $tippYearId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM bet_period WHERE tipp_year_id = ? ORDER BY start_date',
            [$tippYearId]
        );

        return array_map(fn (array $row): BetPeriod => $this->toAggregate($row), $rows);
    }

    public function findActiveOn(int $tippYearId, DateTimeImmutable $date): ?BetPeriod
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM bet_period WHERE tipp_year_id = ? AND ? BETWEEN start_date AND end_date',
            [$tippYearId, $date->format('Y-m-d')]
        );

        return $row === null ? null : $this->toAggregate($row);
    }

    /** @return list<DateRange> */
    public function existingRanges(int $tippYearId, ?int $excludeId = null): array
    {
        $rows = $this->db->fetchAll(
            '
            SELECT start_date, end_date
            FROM bet_period
            WHERE tipp_year_id = ? AND (? IS NULL OR bet_period_id <> ?)
            ORDER BY start_date
            ',
            [$tippYearId, $excludeId, $excludeId]
        );

        return array_map(
            static fn (array $row): DateRange => DateRange::fromStrings(
                Row::string($row, 'start_date'),
                Row::string($row, 'end_date')
            ),
            $rows
        );
    }

    public function nextSequence(int $tippYearId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COALESCE(MAX(sequence), 0) + 1 AS next_sequence FROM bet_period WHERE tipp_year_id = ?',
            [$tippYearId]
        );

        return $row !== null ? Row::int($row, 'next_sequence') : 1;
    }

    /** @param array<string, mixed> $row */
    private function toAggregate(array $row): BetPeriod
    {
        return BetPeriod::fromProjection(
            id: Row::int($row, 'bet_period_id'),
            tippYearId: Row::int($row, 'tipp_year_id'),
            name: Row::string($row, 'name'),
            range: DateRange::fromStrings(
                Row::string($row, 'start_date'),
                Row::string($row, 'end_date')
            ),
            sequence: Row::int($row, 'sequence'),
            version: Row::int($row, 'version')
        );
    }
}
