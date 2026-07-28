<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Event\MemberAdded;
use BettingGame\Domain\Event\PayoutDistributed;
use BettingGame\Domain\Event\TippYearCreated;
use BettingGame\Domain\Event\TippYearStatusChanged;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\ValueObject\TippYearStatus;
use DateTimeImmutable;

/**
 * A tipp year: the period a bet row is valid for and winnings are collected in.
 *
 * The period is freely defined and not tied to the calendar. Its lifecycle
 * gates everything else - tickets only during `running`, distribution only
 * once and only from `closed`.
 */
final class TippYear
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];
    private int $version = 0;
    private int $originalVersion = 0;

    private function __construct(
        private int $id,
        private string $name,
        private DateTimeImmutable $startDate,
        private DateTimeImmutable $endDate,
        private TippYearStatus $status,
        private float $ticketCostPerRow,
        private DateTimeImmutable $createdAt
    ) {
    }

    public static function create(
        int $id,
        string $name,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
        float $ticketCostPerRow
    ): self {
        if ($endDate <= $startDate) {
            throw new BusinessRuleViolationException('End date must be after start date');
        }

        if ($ticketCostPerRow <= 0.0) {
            throw new BusinessRuleViolationException('Cost per row must be positive');
        }

        $year = new self(
            $id,
            $name,
            $startDate,
            $endDate,
            new TippYearStatus(TippYearStatus::PLANNED),
            $ticketCostPerRow,
            new DateTimeImmutable()
        );

        $year->recordEvent(new TippYearCreated(
            (string) $id,
            $name,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            $ticketCostPerRow
        ));

        return $year;
    }

    /**
     * Rehydrates from the read model without recording events.
     */
    public static function fromProjection(
        int $id,
        string $name,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
        TippYearStatus $status,
        float $ticketCostPerRow,
        DateTimeImmutable $createdAt,
        int $version
    ): self {
        $year = new self($id, $name, $startDate, $endDate, $status, $ticketCostPerRow, $createdAt);
        $year->version = $version;
        $year->originalVersion = $version;

        return $year;
    }

    public function start(): void
    {
        $this->changeStatus(TippYearStatus::RUNNING, [TippYearStatus::PLANNED]);
    }

    public function close(): void
    {
        $this->changeStatus(TippYearStatus::CLOSED, [TippYearStatus::RUNNING]);
    }

    public function addMember(int $participantId): void
    {
        if ($this->status->isDistributed()) {
            throw new BusinessRuleViolationException('Cannot add members to a distributed tipp year');
        }

        $this->version++;

        $this->recordEvent(new MemberAdded(
            (string) $this->id,
            $participantId,
            (new DateTimeImmutable())->format('c')
        ));
    }

    /**
     * Books the annual distribution. Only possible once, and only from `closed`.
     *
     * @param list<array<string, mixed>> $shares
     */
    public function distribute(
        float $totalWinnings,
        int $participantCount,
        float $sharePerParticipant,
        array $shares,
        ?string $bookedBy = null
    ): void {
        if ($this->status->isDistributed()) {
            throw new BusinessRuleViolationException('This tipp year has already been distributed');
        }

        if (!$this->status->isClosed()) {
            throw new BusinessRuleViolationException('Only a closed tipp year can be distributed');
        }

        if ($participantCount < 1) {
            throw new BusinessRuleViolationException('A distribution needs at least one participant');
        }

        $this->status = new TippYearStatus(TippYearStatus::DISTRIBUTED);
        $this->version++;

        $this->recordEvent(new PayoutDistributed(
            (string) $this->id,
            $totalWinnings,
            $participantCount,
            $sharePerParticipant,
            $shares,
            $bookedBy
        ));
    }

    public function acceptsTickets(): bool
    {
        return $this->status->acceptsTickets();
    }

    /**
     * @param list<string> $allowedFrom
     */
    private function changeStatus(string $to, array $allowedFrom): void
    {
        if (!in_array($this->status->value(), $allowedFrom, true)) {
            throw new BusinessRuleViolationException(
                sprintf('Cannot go from %s to %s', $this->status->value(), $to)
            );
        }

        $from = $this->status->value();
        $this->status = new TippYearStatus($to);
        $this->version++;

        $this->recordEvent(new TippYearStatusChanged((string) $this->id, $from, $to));
    }

    private function recordEvent(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function startDate(): DateTimeImmutable
    {
        return $this->startDate;
    }

    public function endDate(): DateTimeImmutable
    {
        return $this->endDate;
    }

    public function status(): TippYearStatus
    {
        return $this->status;
    }

    public function ticketCostPerRow(): float
    {
        return $this->ticketCostPerRow;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * Stream version this instance was loaded at - the expected version when appending.
     */
    public function originalVersion(): int
    {
        return $this->originalVersion;
    }
}
