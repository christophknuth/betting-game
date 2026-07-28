<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class DrawWinningsRecorded extends DomainEvent
{
    /**
     * @param array<string, mixed> $winningClasses
     */
    public function __construct(
        private string $drawId,
        private int $ticketId,
        private float $totalAmount,
        private array $winningClasses,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($eventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->drawId;
    }

    public function aggregateType(): string
    {
        return 'draw';
    }

    public function eventType(): string
    {
        return 'draw.winnings_recorded';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'draw_id' => $this->drawId,
            'ticket_id' => $this->ticketId,
            'total_amount' => $this->totalAmount,
            'winning_classes' => $this->winningClasses,
        ];
    }
}
