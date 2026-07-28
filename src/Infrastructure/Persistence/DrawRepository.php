<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Event\DrawWinningsRecorded;
use BettingGame\Domain\Model\Draw;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use DateTimeImmutable;

/**
 * The draw, and what the ticket won in it.
 *
 * `ticket_draw_result` is a projection of this stream, because the winnings are
 * recorded against the draw (`DrawWinningsRecorded`) rather than against the
 * ticket - the administrator reads them off one draw's statement at a time.
 */
final class DrawRepository extends EventSourcedRepository implements DrawRepositoryInterface
{
    private const STREAM_PREFIX = 'draw-';

    public function find(int $id): ?Draw
    {
        $row = $this->db->fetchOne('SELECT * FROM draw WHERE draw_id = ?', [$id]);

        return $row === null ? null : $this->toAggregate($row);
    }

    public function save(Draw $draw): void
    {
        $exists = $draw->isPersisted();

        $version = $this->transactionally(function () use ($draw, $exists): int {
            $events = $draw->releaseEvents();
            $version = $this->append(
                self::STREAM_PREFIX . $draw->id(),
                $events,
                $draw->originalVersion()
            );

            $numbers = $draw->numbers() === null
                ? null
                : json_encode($draw->numbers()->toArray(), JSON_THROW_ON_ERROR);

            if ($exists) {
                $this->db->execute(
                    '
                    UPDATE draw
                    SET numbers = ?, superzahl = ?, status = ?, recorded_at = ?, version = ?
                    WHERE draw_id = ?
                    ',
                    [
                        $numbers,
                        $draw->superzahl()?->value(),
                        $draw->status(),
                        $draw->recordedAt()?->format('Y-m-d H:i:s'),
                        $version,
                        $draw->id(),
                    ]
                );
            } else {
                // uk_draw_date must reject a second draw on the same date
                $this->db->execute(
                    '
                    INSERT INTO draw (
                        draw_id, tipp_year_id, draw_date, numbers, superzahl, status, recorded_at, version
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ',
                    [
                        $draw->id(),
                        $draw->tippYearId(),
                        $draw->drawDate()->format('Y-m-d'),
                        $numbers,
                        $draw->superzahl()?->value(),
                        $draw->status(),
                        $draw->recordedAt()?->format('Y-m-d H:i:s'),
                        $version,
                    ]
                );
            }

            foreach ($events as $event) {
                if ($event instanceof DrawWinningsRecorded) {
                    $this->projectWinnings($event);
                }
            }

            return $version;
        });

        $draw->markCommitted($version);
    }

    public function nextIdentity(): int
    {
        return $this->nextId('draw', 'draw_id');
    }

    public function findByDate(DateTimeImmutable $drawDate): ?Draw
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM draw WHERE draw_date = ?',
            [$drawDate->format('Y-m-d')]
        );

        return $row === null ? null : $this->toAggregate($row);
    }

    /** @return list<Draw> */
    public function findByTippYear(int $tippYearId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM draw WHERE tipp_year_id = ? ORDER BY draw_date DESC',
            [$tippYearId]
        );

        return array_map(fn (array $row): Draw => $this->toAggregate($row), $rows);
    }

    /**
     * B-05: what the whole ticket won per draw - not the caller's own share.
     * A share only comes into existence with the annual distribution.
     *
     * @return list<array<string, mixed>>
     */
    public function findWithWinnings(int $tippYearId): array
    {
        return $this->db->fetchAll(
            '
            SELECT
                d.draw_id, d.draw_date, d.numbers, d.superzahl, d.status,
                r.ticket_id, r.total_amount, r.winning_classes, r.recorded_at
            FROM draw d
            LEFT JOIN ticket_draw_result r ON r.draw_id = d.draw_id
            WHERE d.tipp_year_id = ?
            ORDER BY d.draw_date DESC
            ',
            [$tippYearId]
        );
    }

    public function totalWinnings(int $tippYearId): float
    {
        $row = $this->db->fetchOne(
            '
            SELECT COALESCE(SUM(r.total_amount), 0) AS total
            FROM ticket_draw_result r
            JOIN draw d ON d.draw_id = r.draw_id
            WHERE d.tipp_year_id = ?
            ',
            [$tippYearId]
        );

        return $row === null ? 0.0 : Row::float($row, 'total');
    }

    /**
     * @param list<array{ticketRowId: int, matchedNumbers: int, superzahlMatched: bool,
     *     winningClass: int|null, amount: float}> $matches
     */
    public function saveRowMatches(int $drawId, array $matches): void
    {
        foreach ($matches as $match) {
            $this->db->execute(
                '
                INSERT INTO ticket_row_match (
                    ticket_row_id, draw_id, matched_numbers, superzahl_matched, winning_class, amount
                ) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    matched_numbers = VALUES(matched_numbers),
                    superzahl_matched = VALUES(superzahl_matched),
                    winning_class = VALUES(winning_class),
                    amount = VALUES(amount)
                ',
                [
                    $match['ticketRowId'],
                    $drawId,
                    $match['matchedNumbers'],
                    $match['superzahlMatched'] ? 1 : 0,
                    $match['winningClass'],
                    $match['amount'],
                ]
            );
        }
    }

    private function projectWinnings(DrawWinningsRecorded $event): void
    {
        $payload = $event->toArray();

        $this->db->execute(
            '
            INSERT INTO ticket_draw_result (ticket_id, draw_id, total_amount, winning_classes, recorded_at)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_amount = VALUES(total_amount),
                winning_classes = VALUES(winning_classes),
                recorded_at = VALUES(recorded_at)
            ',
            [
                Row::int($payload, 'ticket_id'),
                Row::string($payload, 'draw_id'),
                Row::float($payload, 'total_amount'),
                json_encode(Row::json($payload, 'winning_classes'), JSON_THROW_ON_ERROR),
                $event->occurredAt()->format('Y-m-d H:i:s'),
            ]
        );
    }

    /** @param array<string, mixed> $row */
    private function toAggregate(array $row): Draw
    {
        $numbers = $row['numbers'] ?? null;
        $superzahl = Row::nullableInt($row, 'superzahl');
        $recordedAt = Row::nullableString($row, 'recorded_at');

        return Draw::fromProjection(
            id: Row::int($row, 'draw_id'),
            tippYearId: Row::int($row, 'tipp_year_id'),
            drawDate: new DateTimeImmutable(Row::string($row, 'draw_date')),
            numbers: $numbers === null ? null : LottoNumbers::fromMixed(Row::json($row, 'numbers')),
            superzahl: $superzahl === null ? null : new Superzahl($superzahl),
            status: Row::string($row, 'status'),
            recordedAt: $recordedAt === null ? null : new DateTimeImmutable($recordedAt),
            version: Row::int($row, 'version')
        );
    }
}
