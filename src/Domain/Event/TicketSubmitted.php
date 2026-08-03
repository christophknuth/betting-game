<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class TicketSubmitted extends DomainEvent
{
    /**
     * The period and the draw count are derived from the Laufzeit, and all four
     * are written: an event says what happened, and recomputing the period of a
     * ten-year-old ticket from a rule that has since changed is not that.
     *
     * `durationWeeks` and `drawDays` are nullable for the tickets handed in
     * before they were asked for - see the projector.
     *
     * @param list<array<string, mixed>> $rows bet row id plus the numbers as submitted
     */
    public function __construct(
        private string $ticketId,
        private int $tippYearId,
        private string $periodStart,
        private string $periodEnd,
        private ?int $durationWeeks,
        private ?string $drawDays,
        private int $drawCount,
        private float $totalCost,
        private array $rows,
        private ?int $superzahl = null,
        private ?string $lotteryReference = null,
        private float $processingFee = 0.0,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($eventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->ticketId;
    }

    public function aggregateType(): string
    {
        return 'ticket';
    }

    public function eventType(): string
    {
        return 'ticket.submitted';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'tipp_year_id' => $this->tippYearId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'duration_weeks' => $this->durationWeeks,
            'draw_days' => $this->drawDays,
            'draw_count' => $this->drawCount,
            'total_cost' => $this->totalCost,
            'rows' => $this->rows,
            'superzahl' => $this->superzahl,
            'lottery_reference' => $this->lotteryReference,
            'processing_fee' => $this->processingFee,
        ];
    }
}
