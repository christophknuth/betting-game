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
    public function __construct(private Db $db)
    {
    }

    public function name(): string
    {
        return 'draw_read_model';
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return ['draw.recorded', 'draw.winnings_recorded'];
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
            'draw.recorded' => $this->recorded($data, $record),
            'draw.winnings_recorded' => $this->winningsRecorded($data, $record),
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
