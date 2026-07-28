<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class FeeCharged extends DomainEvent
{
    public function __construct(
        private string $feeId,
        private int $participantId,
        private int $ticketId,
        private float $amount,
        private string $dueDate,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($eventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->feeId;
    }

    public function aggregateType(): string
    {
        return 'fee';
    }

    public function eventType(): string
    {
        return 'fee.charged';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fee_id' => $this->feeId,
            'participant_id' => $this->participantId,
            'ticket_id' => $this->ticketId,
            'amount' => $this->amount,
            'due_date' => $this->dueDate,
        ];
    }
}
