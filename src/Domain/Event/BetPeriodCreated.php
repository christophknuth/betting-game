<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class BetPeriodCreated extends DomainEvent
{
    public function __construct(
        private string $betPeriodId,
        private int $tippYearId,
        private string $name,
        private string $startDate,
        private string $endDate,
        private int $sequence = 1,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($eventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->betPeriodId;
    }

    public function aggregateType(): string
    {
        return 'bet_period';
    }

    public function eventType(): string
    {
        return 'bet_period.created';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'bet_period_id' => $this->betPeriodId,
            'tipp_year_id' => $this->tippYearId,
            'name' => $this->name,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            // Part of the period's state, so it belongs in the event - without
            // it a rebuilt projection would lose the ordering.
            'sequence' => $this->sequence,
        ];
    }
}
