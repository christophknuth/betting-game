<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Projection;

use BettingGame\Application\Projection\Projector;
use BettingGame\Domain\Model\Ticket;
use BettingGame\Domain\Repository\RecordedEvent;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Support\Row;

final class TicketProjector implements Projector
{
    public const NAME = 'ticket_read_model';

    public const EVENT_SUBMITTED = 'ticket.submitted';

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
        return [self::EVENT_SUBMITTED];
    }

    public function reset(): void
    {
        $this->db->execute('DELETE FROM ticket_row');
        $this->db->execute('DELETE FROM ticket');
        // DELETE leaves the counter where it was, so a rebuild would hand out
        // different ticket_row ids than the original run. Resetting it keeps a
        // rebuilt read model byte-identical, which is what makes it checkable.
        $this->db->execute('ALTER TABLE ticket_row AUTO_INCREMENT = 1');
    }

    public function apply(RecordedEvent $record): void
    {
        if ($record->event->eventType() !== self::EVENT_SUBMITTED) {
            return;
        }

        $data = $record->event->toArray();
        $ticketId = Row::int($data, 'ticket_id');

        $rows = $data['rows'] ?? [];
        $rows = is_array($rows) ? $rows : [];

        $this->db->execute(
            '
            INSERT INTO ticket (
                ticket_id, tipp_year_id, period_start, period_end, duration_weeks, draw_days,
                lottery_reference, superzahl, row_count, draw_count, processing_fee, total_cost,
                status, submitted_at, version
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ',
            [
                $ticketId,
                Row::int($data, 'tipp_year_id'),
                Row::string($data, 'period_start'),
                Row::string($data, 'period_end'),
                // Nullable for the same reason as the fee below: a ticket
                // handed in before the Laufzeit was recorded has none, and its
                // period and draw count are the truth all the same.
                Row::nullableInt($data, 'duration_weeks'),
                Row::nullableString($data, 'draw_days'),
                Row::nullableString($data, 'lottery_reference'),
                Row::nullableInt($data, 'superzahl'),
                count($rows),
                Row::int($data, 'draw_count'),
                // Nullable, not required: tickets submitted before the
                // Bearbeitungsentgelt existed carry no such field, and the
                // event log is immutable. Demanding it would break a rebuild
                // on every event written before this change.
                Row::nullableFloat($data, 'processing_fee') ?? 0.0,
                Row::float($data, 'total_cost'),
                Ticket::SUBMITTED,
                $record->event->occurredAt()->format('Y-m-d H:i:s'),
                $record->version,
            ]
        );

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $numbers = $row['numbers'] ?? [];

            $this->db->execute(
                'INSERT INTO ticket_row (ticket_id, bet_row_id, numbers) VALUES (?, ?, ?)',
                [
                    $ticketId,
                    Row::int($row, 'bet_row_id'),
                    json_encode(
                        LottoNumbers::fromMixed(is_array($numbers) ? $numbers : [])->toArray(),
                        JSON_THROW_ON_ERROR
                    ),
                ]
            );
        }
    }
}
