<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\MemberAdded;
use BettingGame\Domain\Event\PayoutDistributed;
use BettingGame\Domain\Event\TippYearCreated;
use BettingGame\Domain\Event\TippYearStatusChanged;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\ValueObject\DateRange;
use BettingGame\Domain\ValueObject\ProcessingFees;
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
    use RecordsEvents;

    private function __construct(
        private int $id,
        private string $name,
        private DateTimeImmutable $startDate,
        private DateTimeImmutable $endDate,
        private TippYearStatus $status,
        private float $ticketCostPerRow,
        private ProcessingFees $processingFees,
        private DateTimeImmutable $createdAt
    ) {
    }

    public static function create(
        int $id,
        string $name,
        DateTimeImmutable $startDate,
        DateTimeImmutable $endDate,
        float $ticketCostPerRow,
        ?ProcessingFees $processingFees = null
    ): self {
        if ($endDate <= $startDate) {
            throw new BusinessRuleViolationException('End date must be after start date');
        }

        if ($ticketCostPerRow <= 0.0) {
            throw new BusinessRuleViolationException('Cost per row must be positive');
        }

        $fees = $processingFees ?? ProcessingFees::none();

        $year = new self(
            $id,
            $name,
            $startDate,
            $endDate,
            new TippYearStatus(TippYearStatus::PLANNED),
            $ticketCostPerRow,
            $fees,
            new DateTimeImmutable()
        );

        $year->recordEvent(new TippYearCreated(
            (string) $id,
            $name,
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            $ticketCostPerRow,
            $fees->singleWeek(),
            $fees->multiWeek()
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
        ProcessingFees $processingFees,
        DateTimeImmutable $createdAt,
        int $version
    ): self {
        $year = new self(
            $id,
            $name,
            $startDate,
            $endDate,
            $status,
            $ticketCostPerRow,
            $processingFees,
            $createdAt
        );
        $year->markCommitted($version);

        return $year;
    }

    /**
     * Tipp years must not overlap, for the same reason bet periods must not:
     * a draw on a given day has to belong to exactly one year, otherwise it
     * would count towards two distributions.
     *
     * @param list<DateRange> $existing
     */
    public static function assertNoOverlap(DateRange $range, array $existing): void
    {
        foreach ($existing as $other) {
            if ($range->overlaps($other)) {
                throw new BusinessRuleViolationException(
                    sprintf('The tipp year %s overlaps the existing tipp year %s', $range, $other)
                );
            }
        }
    }

    public function range(): DateRange
    {
        return new DateRange($this->startDate, $this->endDate);
    }

    /**
     * Moves the year to another status. Every path is allowed.
     *
     * Deliberately not a fixed state machine. Which year runs is an operational
     * decision, and the administrator has to be able to correct one: a year
     * closed a week too early, one started by mistake, one whose distribution
     * was booked before the last draw was in. A graph that only goes forwards
     * would not prevent those corrections - it would push them into the
     * database, where they leave no event behind and nobody can see them.
     *
     * The one rule left here is that something has to change. Recording
     * "running -> running" would put an event in the history saying nothing
     * happened.
     *
     * That at most one year runs at a time is *not* decided here: it spans
     * aggregates, and this one cannot see the others. It belongs to
     * ChangeTippYearStatusHandler and, under concurrency, to the unique key on
     * `tipp_year.running_marker`.
     */
    public function changeStatusTo(TippYearStatus $to): void
    {
        if ($this->status->value() === $to->value()) {
            throw new BusinessRuleViolationException(
                sprintf('This tipp year is already %s', $to->value())
            );
        }

        $from = $this->status->value();
        $this->status = $to;
        $this->version++;

        $this->recordEvent(new TippYearStatusChanged((string) $this->id, $from, $to->value()));
    }

    public function start(): void
    {
        $this->changeStatusTo(new TippYearStatus(TippYearStatus::RUNNING));
    }

    public function close(): void
    {
        $this->changeStatusTo(new TippYearStatus(TippYearStatus::CLOSED));
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

    public function processingFees(): ProcessingFees
    {
        return $this->processingFees;
    }

    public function ticketCostPerRow(): float
    {
        return $this->ticketCostPerRow;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
