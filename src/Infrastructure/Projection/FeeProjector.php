<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Projection;

use BettingGame\Application\Projection\Projector;
use BettingGame\Domain\Model\Fee;
use BettingGame\Domain\Repository\RecordedEvent;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Support\Row;

final class FeeProjector implements Projector
{
    public function __construct(private Db $db)
    {
    }

    public function name(): string
    {
        return 'fee_read_model';
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return ['fee.charged', 'fee.payment_recorded'];
    }

    public function reset(): void
    {
        $this->db->execute('DELETE FROM fee');
    }

    public function apply(RecordedEvent $record): void
    {
        $data = $record->event->toArray();

        match ($record->event->eventType()) {
            'fee.charged' => $this->db->execute(
                '
                INSERT INTO fee (
                    fee_id, participant_id, ticket_id, amount, due_date, payment_status, version
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ',
                [
                    Row::int($data, 'fee_id'),
                    Row::int($data, 'participant_id'),
                    Row::int($data, 'ticket_id'),
                    Row::float($data, 'amount'),
                    Row::string($data, 'due_date'),
                    Fee::OPEN,
                    $record->version,
                ]
            ),
            'fee.payment_recorded' => $this->db->execute(
                '
                UPDATE fee
                SET payment_status = ?, paid_at = ?, payment_method = ?,
                    booked_by = ?, note = ?, version = ?
                WHERE fee_id = ?
                ',
                [
                    Row::string($data, 'payment_status'),
                    Row::nullableString($data, 'paid_at'),
                    Row::nullableString($data, 'payment_method'),
                    Row::nullableString($data, 'booked_by'),
                    Row::nullableString($data, 'note'),
                    $record->version,
                    Row::int($data, 'fee_id'),
                ]
            ),
            default => null,
        };
    }
}
