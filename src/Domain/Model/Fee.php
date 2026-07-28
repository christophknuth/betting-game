<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\FeeCharged;
use BettingGame\Domain\Event\FeePaymentRecorded;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use DateTimeImmutable;

/**
 * What one participant owes for one ticket.
 *
 * A fee comes into existence with the ticket - the ticket cost split evenly
 * across the rows on it - and is due during the period the ticket covers.
 *
 * Only the administrator books payments, so the aggregate does not care who is
 * calling; it only guards that a fee is not settled twice, because that would
 * put the same money into the books two times.
 */
final class Fee
{
    use RecordsEvents;

    public const OPEN = 'open';
    public const PAID = 'paid';
    public const WAIVED = 'waived';

    private function __construct(
        private int $id,
        private int $participantId,
        private int $ticketId,
        private float $amount,
        private DateTimeImmutable $dueDate,
        private string $status,
        private ?DateTimeImmutable $paidAt,
        private ?string $paymentMethod,
        private ?string $bookedBy,
        private ?string $note
    ) {
    }

    public static function charge(
        int $id,
        int $participantId,
        int $ticketId,
        float $amount,
        DateTimeImmutable $dueDate
    ): self {
        if ($amount <= 0.0) {
            throw new BusinessRuleViolationException('A fee must be a positive amount');
        }

        $fee = new self(
            $id,
            $participantId,
            $ticketId,
            round($amount, 2),
            $dueDate,
            self::OPEN,
            null,
            null,
            null,
            null
        );

        $fee->recordEvent(new FeeCharged(
            (string) $id,
            $participantId,
            $ticketId,
            round($amount, 2),
            $dueDate->format('Y-m-d')
        ));

        return $fee;
    }

    /**
     * Rehydrates from the read model without recording events.
     */
    public static function fromProjection(
        int $id,
        int $participantId,
        int $ticketId,
        float $amount,
        DateTimeImmutable $dueDate,
        string $status,
        ?DateTimeImmutable $paidAt,
        ?string $paymentMethod,
        ?string $bookedBy,
        ?string $note,
        int $version
    ): self {
        $fee = new self(
            $id,
            $participantId,
            $ticketId,
            $amount,
            $dueDate,
            $status,
            $paidAt,
            $paymentMethod,
            $bookedBy,
            $note
        );
        $fee->markCommitted($version);

        return $fee;
    }

    /**
     * Books the payment. The administrator supplies the date, because money
     * usually arrives before anyone gets around to recording it.
     */
    public function markPaid(
        ?string $paymentMethod = null,
        ?string $bookedBy = null,
        ?DateTimeImmutable $paidAt = null,
        ?string $note = null
    ): void {
        $this->settle(self::PAID, $paidAt ?? new DateTimeImmutable(), $paymentMethod, $bookedBy, $note);
    }

    /**
     * Writes the fee off. A waiver needs a reason - it is a decision, not a fact.
     */
    public function waive(string $reason, ?string $bookedBy = null): void
    {
        if (trim($reason) === '') {
            throw new BusinessRuleViolationException('Waiving a fee requires a reason');
        }

        $this->settle(self::WAIVED, null, null, $bookedBy, trim($reason));
    }

    private function settle(
        string $status,
        ?DateTimeImmutable $paidAt,
        ?string $paymentMethod,
        ?string $bookedBy,
        ?string $note
    ): void {
        if ($this->status !== self::OPEN) {
            throw new BusinessRuleViolationException(
                sprintf('This fee is already %s', $this->status)
            );
        }

        $this->status = $status;
        $this->paidAt = $paidAt;
        $this->paymentMethod = $paymentMethod;
        $this->bookedBy = $bookedBy;
        $this->note = $note;
        $this->version++;

        $this->recordEvent(new FeePaymentRecorded(
            (string) $this->id,
            $this->participantId,
            $status,
            $paidAt?->format('Y-m-d H:i:s'),
            $paymentMethod,
            $bookedBy,
            $note
        ));
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function participantId(): int
    {
        return $this->participantId;
    }

    public function ticketId(): int
    {
        return $this->ticketId;
    }

    public function amount(): float
    {
        return $this->amount;
    }

    public function dueDate(): DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function paidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function paymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function bookedBy(): ?string
    {
        return $this->bookedBy;
    }

    public function note(): ?string
    {
        return $this->note;
    }
}
