<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Projection;

use BettingGame\Application\Projection\Projector;
use BettingGame\Domain\Repository\RecordedEvent;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Support\Row;

final class BetRowProjector implements Projector
{
    public function __construct(private Db $db)
    {
    }

    public function name(): string
    {
        return 'bet_row_read_model';
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return ['bet_row.assigned', 'bet_row.replaced'];
    }

    public function reset(): void
    {
        $this->db->execute('DELETE FROM bet_row');
    }

    public function apply(RecordedEvent $record): void
    {
        $data = $record->event->toArray();

        match ($record->event->eventType()) {
            'bet_row.assigned' => $this->db->execute(
                '
                INSERT INTO bet_row (bet_row_id, participant_id, bet_period_id, numbers, assigned_at, version)
                VALUES (?, ?, ?, ?, ?, ?)
                ',
                [
                    Row::int($data, 'bet_row_id'),
                    Row::int($data, 'participant_id'),
                    Row::int($data, 'bet_period_id'),
                    $this->numbers($data, 'numbers'),
                    $record->event->occurredAt()->format('Y-m-d H:i:s'),
                    $record->version,
                ]
            ),
            'bet_row.replaced' => $this->db->execute(
                'UPDATE bet_row SET numbers = ?, version = ? WHERE bet_row_id = ?',
                [$this->numbers($data, 'numbers'), $record->version, Row::int($data, 'bet_row_id')]
            ),
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function numbers(array $data, string $key): string
    {
        $value = $data[$key] ?? [];

        return json_encode(
            LottoNumbers::fromMixed(is_array($value) ? $value : [])->toArray(),
            JSON_THROW_ON_ERROR
        );
    }
}
