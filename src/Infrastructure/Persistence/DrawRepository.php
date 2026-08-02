<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Support\Row;
use BettingGame\Domain\Event\DrawWinningsRecorded;
use BettingGame\Domain\Model\Draw;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use DateTimeImmutable;
use BettingGame\Infrastructure\Projection\DrawProjector;

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

    /** The read model this repository keeps current; see EventSourcedRepository. */
    protected function projectionName(): string
    {
        return DrawProjector::NAME;
    }

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
     * The ticket is joined by its period, not through the result row: since
     * B-24 a draw shows the rows that took part in it, and those exist long
     * before anyone knows what they won. `total_amount` stays null until then,
     * which is not the same as zero.
     *
     * @return list<array<string, mixed>>
     */
    public function findWithWinnings(int $tippYearId): array
    {
        return $this->db->fetchAll(
            '
            SELECT
                d.draw_id, d.draw_date, d.numbers, d.superzahl, d.status,
                t.ticket_id, t.row_count,
                r.total_amount, r.winning_classes, r.recorded_at
            FROM draw d
            LEFT JOIN ticket t ON t.ticket_id = (
                SELECT ticket_id FROM ticket
                WHERE tipp_year_id = d.tipp_year_id
                  AND d.draw_date BETWEEN period_start AND period_end
                ORDER BY period_start DESC
                LIMIT 1
            )
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

    /** @return array{matchedNumbers: int, superzahlMatched: bool}|null */
    public function bestMatchOf(int $drawId): ?array
    {
        $row = $this->db->fetchOne(
            '
            SELECT matched_numbers, superzahl_matched
            FROM ticket_row_match
            WHERE draw_id = ?
            ORDER BY matched_numbers DESC, superzahl_matched DESC
            LIMIT 1
            ',
            [$drawId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'matchedNumbers' => Row::int($row, 'matched_numbers'),
            'superzahlMatched' => Row::bool($row, 'superzahl_matched'),
        ];
    }

    /** @return list<array{winningClass: int, rowCount: int, amount: float}> */
    public function winningClassesOf(int $drawId): array
    {
        $rows = $this->db->fetchAll(
            '
            SELECT winning_class, COUNT(*) AS row_count, COALESCE(SUM(amount), 0) AS amount
            FROM ticket_row_match
            WHERE draw_id = ? AND winning_class IS NOT NULL
            GROUP BY winning_class
            ORDER BY winning_class
            ',
            [$drawId]
        );

        return array_map(
            static fn (array $row): array => [
                'winningClass' => Row::int($row, 'winning_class'),
                'rowCount' => Row::int($row, 'row_count'),
                'amount' => Row::float($row, 'amount'),
            ],
            $rows
        );
    }

    /**
     * @return list<array{ticketRowId: int, participantId: int, displayName: string,
     *     numbers: list<int>, matchedNumbers: int|null, superzahlMatched: bool,
     *     winningClass: int|null, amount: float}>
     */
    public function rowResultsOf(int $drawId, int $ticketId): array
    {
        $rows = $this->db->fetchAll(
            '
            SELECT
                tr.ticket_row_id, tr.numbers, br.participant_id, p.display_name,
                m.matched_numbers, m.superzahl_matched, m.winning_class, m.amount
            FROM ticket_row tr
            JOIN bet_row br ON br.bet_row_id = tr.bet_row_id
            JOIN participant p ON p.participant_id = br.participant_id
            LEFT JOIN ticket_row_match m ON m.ticket_row_id = tr.ticket_row_id AND m.draw_id = ?
            WHERE tr.ticket_id = ?
            ORDER BY p.display_name, tr.ticket_row_id
            ',
            [$drawId, $ticketId]
        );

        return array_map(
            static fn (array $row): array => [
                'ticketRowId' => Row::int($row, 'ticket_row_id'),
                'participantId' => Row::int($row, 'participant_id'),
                'displayName' => Row::string($row, 'display_name'),
                // The snapshot, not the current bet row: this is what took part
                'numbers' => LottoNumbers::fromMixed(Row::json($row, 'numbers'))->toArray(),
                'matchedNumbers' => Row::nullableInt($row, 'matched_numbers'),
                // Null where the LEFT JOIN found no evaluation, and Row::bool
                // would rather throw than call that false
                'superzahlMatched' => ($row['superzahl_matched'] ?? null) !== null
                    && Row::bool($row, 'superzahl_matched'),
                'winningClass' => Row::nullableInt($row, 'winning_class'),
                'amount' => Row::nullableFloat($row, 'amount') ?? 0.0,
            ],
            $rows
        );
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
                json_encode($payload['winning_classes'] ?? [], JSON_THROW_ON_ERROR),
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
