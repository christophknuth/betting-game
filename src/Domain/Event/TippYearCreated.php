<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class TippYearCreated extends DomainEvent
{
    public function __construct(
        private string $tippYearId,
        private string $name,
        private string $startDate,
        private string $endDate,
        private float $ticketCostPerRow,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($eventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->tippYearId;
    }

    public function aggregateType(): string
    {
        return 'tipp_year';
    }

    public function eventType(): string
    {
        return 'tipp_year.created';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tipp_year_id' => $this->tippYearId,
            'name' => $this->name,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'ticket_cost_per_row' => $this->ticketCostPerRow,
        ];
    }
}
