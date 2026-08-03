<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Projection;

use BettingGame\Application\Projection\Projector;
use BettingGame\Domain\Model\Draw;
use BettingGame\Domain\Repository\RecordedEvent;
use BettingGame\Domain\Service\WinningsDistribution;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Support\Row;

/**
 * The draw, what the ticket won, and the per-row breakdown.
 *
 * `ticket_row_match` is the one read model here that no event carries: the
 * command only records the ticket's total. It is recomputed instead, through
 * the same domain service the command handler uses - which is why that service
 * exists rather than the logic sitting inside the handler.
 */
final class DrawProjector implements Projector
{
    public const NAME = 'draw_read_model';

    public const EVENT_RECORDED = 'draw.recorded';

    public const EVENT_WINNINGS_RECORDED = 'draw.winnings_recorded';

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
        return [self::EVENT_RECORDED, self::EVENT_WINNINGS_RECORDED];
    }

    public function reset(): void
    {
        $this->db->execute('DELETE FROM ticket_row_match');
        $this->db->execute('DELETE FROM ticket_draw_result');
        $this->db->execute('DELETE FROM draw');
        $this->db->execute('ALTER TABLE ticket_row_match AUTO_INCREMENT = 1');
        $this->db->execute('ALTER TABLE ticket_draw_result AUTO_INCREMENT = 1');
    }

    public function apply(RecordedEvent $record): void
    {
        $data = $record->event->toArray();

        match ($record->event->eventType()) {
            self::EVENT_RECORDED => $this->recorded($data, $record),
            self::EVENT_WINNINGS_RECORDED => $this->winningsRecorded($data, $record),
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function recorded(array $data, RecordedEvent $record): void
    {
        $numbers = $data['numbers'] ?? [];

        $this->db->execute(
            '
            INSERT INTO draw (draw_id, tipp_year_id, draw_date, numbers, superzahl, status, recorded_at, version)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ',
            [
                Row::int($data, 'draw_id'),
                Row::int($data, 'tipp_year_id'),
                Row::string($data, 'draw_date'),
                json_encode(
                    LottoNumbers::fromMixed(is_array($numbers) ? $numbers : [])->toArray(),
                    JSON_THROW_ON_ERROR
                ),
                Row::int($data, 'superzahl'),
                Draw::DRAWN,
                $record->event->occurredAt()->format('Y-m-d H:i:s'),
                $record->version,
            ]
        );

        // B-22: the rows are evaluated with the draw, so a rebuild has to
        // produce them here too - otherwise a rebuilt read model would show no
        // hits for every draw whose winnings have not been recorded yet.
        $ticketId = $this->coveringTicketId(Row::int($data, 'tipp_year_id'), Row::string($data, 'draw_date'));

        if ($ticketId !== null) {
            $this->rebuildRowMatches(Row::int($data, 'draw_id'), $ticketId, 0.0, []);
        }
    }

    /**
     * The ticket a draw belongs to: the one whose period contains its date.
     *
     * On a rebuild this can legitimately come back empty - the ticket's own
     * event may not have been replayed yet, or the draw was recorded before the
     * ticket was handed in. The winnings then bring the matches in later.
     */
    private function coveringTicketId(int $tippYearId, string $drawDate): ?int
    {
        $row = $this->db->fetchOne(
            '
            SELECT ticket_id
            FROM ticket
            WHERE tipp_year_id = ? AND period_start <= ? AND period_end >= ?
            ORDER BY period_start DESC
            LIMIT 1
            ',
            [$tippYearId, $drawDate, $drawDate]
        );

        return $row === null ? null : Row::int($row, 'ticket_id');
    }

    /** @param array<string, mixed> $data */
    private function winningsRecorded(array $data, RecordedEvent $record): void
    {
        $drawId = Row::int($data, 'draw_id');
        $ticketId = Row::int($data, 'ticket_id');
        $totalAmount = Row::float($data, 'total_amount');

        $this->db->execute(
            '
            INSERT INTO ticket_draw_result (ticket_id, draw_id, total_amount, winning_classes, recorded_at)
            VALUES (?, ?, ?, ?, ?)
            ',
            [
                $ticketId,
                $drawId,
                $totalAmount,
                json_encode($data['winning_classes'] ?? [], JSON_THROW_ON_ERROR),
                $record->event->occurredAt()->format('Y-m-d H:i:s'),
            ]
        );

        $this->db->execute(
            'UPDATE draw SET status = ?, version = ? WHERE draw_id = ?',
            [Draw::EVALUATED, $record->version, $drawId]
        );

        $this->rebuildRowMatches($drawId, $ticketId, $totalAmount, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function rebuildRowMatches(int $drawId, int $ticketId, float $totalAmount, array $data): void
    {
        $draw = $this->db->fetchOne('SELECT numbers, superzahl FROM draw WHERE draw_id = ?', [$drawId]);
        $ticket = $this->db->fetchOne('SELECT superzahl FROM ticket WHERE ticket_id = ?', [$ticketId]);

        if ($draw === null || $ticket === null) {
            return;
        }

        $rows = $this->db->fetchAll(
            'SELECT ticket_row_id, numbers FROM ticket_row WHERE ticket_id = ? ORDER BY ticket_row_id',
            [$ticketId]
        );

        if ($rows === []) {
            return;
        }

        $drawSuperzahl = Row::nullableInt($draw, 'superzahl');
        $ticketSuperzahl = Row::nullableInt($ticket, 'superzahl');

        $matches = WinningsDistribution::of(
            LottoNumbers::fromMixed(Row::json($draw, 'numbers')),
            $drawSuperzahl === null ? null : new Superzahl($drawSuperzahl),
            $ticketSuperzahl === null ? null : new Superzahl($ticketSuperzahl),
            array_map(
                static fn (array $row): array => [
                    'ticketRowId' => Row::int($row, 'ticket_row_id'),
                    'numbers' => LottoNumbers::fromMixed(Row::json($row, 'numbers')),
                ],
                $rows
            ),
            $totalAmount,
            $this->breakdown($data)
        );

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

    /**
     * What each class contributed to the ticket, out of the event.
     *
     * Only `amount` is read, although the event also carries what one row of
     * the class was paid and how many rows that was: the attribution divides
     * the class total among exactly those rows and arrives at the same figure -
     * and that is the one field the events written before B-23 asked for the
     * amount per row have as well.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{winningClass: int, amount: float}>
     */
    private function breakdown(array $data): array
    {
        $raw = $data['winning_classes'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $breakdown = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $breakdown[] = [
                'winningClass' => Row::int($entry, 'winning_class'),
                'amount' => Row::float($entry, 'amount'),
            ];
        }

        return $breakdown;
    }
}
