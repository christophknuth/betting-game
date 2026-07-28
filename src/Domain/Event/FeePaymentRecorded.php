<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class FeePaymentRecorded extends DomainEvent
{
    public function __construct(
        private string $feeId,
        private int $participantId,
        private string $paymentStatus,
        private ?string $paidAt,
        private ?string $paymentMethod,
        private ?string $bookedBy,
        private ?string $note = null,
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
        return 'fee.payment_recorded';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fee_id' => $this->feeId,
            'participant_id' => $this->participantId,
            'payment_status' => $this->paymentStatus,
            'paid_at' => $this->paidAt,
            'payment_method' => $this->paymentMethod,
            'booked_by' => $this->bookedBy,
            'note' => $this->note,
        ];
    }
}
