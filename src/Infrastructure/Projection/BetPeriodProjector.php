<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Projection;

use BettingGame\Application\Projection\Projector;
use BettingGame\Domain\Repository\RecordedEvent;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Support\Row;

final class BetPeriodProjector implements Projector
{
    public const NAME = 'bet_period_read_model';

    public const EVENT_CREATED = 'bet_period.created';

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
        return [self::EVENT_CREATED];
    }

    public function reset(): void
    {
        $this->db->execute('DELETE FROM bet_period');
    }

    public function apply(RecordedEvent $record): void
    {
        if ($record->event->eventType() !== self::EVENT_CREATED) {
            return;
        }

        $data = $record->event->toArray();

        $this->db->execute(
            '
            INSERT INTO bet_period (bet_period_id, tipp_year_id, name, start_date, end_date, sequence, version)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ',
            [
                Row::int($data, 'bet_period_id'),
                Row::int($data, 'tipp_year_id'),
                Row::string($data, 'name'),
                Row::string($data, 'start_date'),
                Row::string($data, 'end_date'),
                Row::nullableInt($data, 'sequence') ?? 1,
                $record->version,
            ]
        );
    }
}
