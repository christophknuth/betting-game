<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\TicketSubmitted;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\ValueObject\DrawSchedule;
use BettingGame\Domain\ValueObject\EvenSplit;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use DateTimeImmutable;

/**
 * The shared ticket submitted once per month.
 *
 * It carries a snapshot of every active bet row. A later correction of a bet
 * row does not reach back into a submitted ticket - that is why the numbers are
 * copied rather than referenced.
 *
 * What was handed in is a start date and a `DrawSchedule`; the period's end and
 * the number of draws are derived from those and then kept as facts of the
 * ticket. Keeping them rather than recomputing them on read is the same
 * reasoning as for the row numbers and the Bearbeitungsentgelt: what a
 * submitted ticket cost must not change because a rule was rewritten later.
 */
final class Ticket
{
    use RecordsEvents;

    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const SETTLED = 'settled';

    /**
     * @param list<array{betRowId: int, participantId: int, numbers: LottoNumbers}> $rows
     */
    private function __construct(
        private int $id,
        private int $tippYearId,
        private DateTimeImmutable $periodStart,
        private DateTimeImmutable $periodEnd,
        private int $drawCount,
        private ?DrawSchedule $schedule,
        private float $totalCost,
        private array $rows,
        private ?Superzahl $superzahl,
        private ?string $lotteryReference,
        private string $status,
        private ?DateTimeImmutable $submittedAt,
        private float $processingFee = 0.0
    ) {
    }

    /**
     * @param list<array{betRowId: int, participantId: int, numbers: LottoNumbers}> $rows
     */
    public static function submit(
        int $id,
        int $tippYearId,
        DateTimeImmutable $periodStart,
        DrawSchedule $schedule,
        float $ticketCostPerRow,
        array $rows,
        ?Superzahl $superzahl = null,
        ?string $lotteryReference = null,
        float $processingFee = 0.0
    ): self {
        if ($rows === []) {
            throw new BusinessRuleViolationException('A ticket needs at least one bet row');
        }

        if ($processingFee < 0.0) {
            throw new BusinessRuleViolationException('A processing fee cannot be negative');
        }

        // Neither of these is a number anyone hands in: the Laufzeit and the
        // chosen draw days decide them, and the schedule has already refused
        // anything that would leave the ticket without a draw or without a
        // period.
        $periodEnd = $schedule->periodEnd($periodStart);
        $drawCount = $schedule->drawCount();

        // The Bearbeitungsentgelt is charged once for the Spielauftrag, not per
        // row and not per draw - which is why it is added rather than folded
        // into the row price, and why the share below no longer divides evenly.
        $totalCost = round(count($rows) * $drawCount * $ticketCostPerRow + $processingFee, 2);

        $ticket = new self(
            $id,
            $tippYearId,
            $periodStart,
            $periodEnd,
            $drawCount,
            $schedule,
            $totalCost,
            array_values($rows),
            $superzahl,
            $lotteryReference,
            self::SUBMITTED,
            new DateTimeImmutable(),
            $processingFee
        );

        $ticket->recordEvent(new TicketSubmitted(
            (string) $id,
            $tippYearId,
            $periodStart->format('Y-m-d'),
            $periodEnd->format('Y-m-d'),
            $schedule->durationWeeks(),
            $schedule->drawDays(),
            $drawCount,
            $totalCost,
            array_map(
                static fn (array $row): array => [
                    'bet_row_id' => $row['betRowId'],
                    'participant_id' => $row['participantId'],
                    'numbers' => $row['numbers']->toArray(),
                ],
                array_values($rows)
            ),
            $superzahl?->value(),
            $lotteryReference,
            $processingFee
        ));

        return $ticket;
    }

    /**
     * Rehydrates from the read model without recording events.
     *
     * The schedule is nullable, the period and the draw count are not: a ticket
     * handed in before the Laufzeit was recorded has a period and a number of
     * draws all the same, and those are what it was billed on.
     *
     * @param list<array{betRowId: int, participantId: int, numbers: LottoNumbers}> $rows
     */
    public static function fromProjection(
        int $id,
        int $tippYearId,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        int $drawCount,
        ?DrawSchedule $schedule,
        float $totalCost,
        array $rows,
        ?Superzahl $superzahl,
        ?string $lotteryReference,
        string $status,
        ?DateTimeImmutable $submittedAt,
        int $version,
        float $processingFee = 0.0
    ): self {
        $ticket = new self(
            $id,
            $tippYearId,
            $periodStart,
            $periodEnd,
            $drawCount,
            $schedule,
            $totalCost,
            $rows,
            $superzahl,
            $lotteryReference,
            $status,
            $submittedAt,
            $processingFee
        );
        $ticket->markCommitted($version);

        return $ticket;
    }

    /**
     * What each participant owes, in the order of the rows on the ticket.
     *
     * The cost is split evenly across the rows, so everyone pays the same
     * regardless of when they joined the year - but "evenly" has to mean in
     * whole cents. Once the Bearbeitungsentgelt is added, the total is no
     * longer a multiple of the row count: 3 rows x 9 draws x 1.20 plus 1.00 is
     * 33.40, and a third of that is 11.1333... Rounding each share separately
     * would bill 33.39 and quietly lose a cent, every single ticket.
     *
     * `EvenSplit` divides in cents and puts the remainder on the first share -
     * the same convention B-13 states for the payout and B-09 uses per row.
     *
     * @return list<float> one share per row, summing exactly to the total cost
     */
    public function feeShares(): array
    {
        return EvenSplit::of($this->totalCost, count($this->rows));
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * @return list<int>
     */
    public function participantIds(): array
    {
        return array_values(array_unique(
            array_map(static fn (array $row): int => $row['participantId'], $this->rows)
        ));
    }

    public function id(): int
    {
        return $this->id;
    }

    public function tippYearId(): int
    {
        return $this->tippYearId;
    }

    public function periodStart(): DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function periodEnd(): DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function drawCount(): int
    {
        return $this->drawCount;
    }

    /**
     * What was handed in - the Laufzeit and the draw days.
     *
     * Null for tickets from before those were recorded; their period and draw
     * count still say what they played.
     */
    public function schedule(): ?DrawSchedule
    {
        return $this->schedule;
    }

    /** The Bearbeitungsentgelt charged for this Spielauftrag, as a snapshot. */
    public function processingFee(): float
    {
        return $this->processingFee;
    }

    public function totalCost(): float
    {
        return $this->totalCost;
    }

    /**
     * @return list<array{betRowId: int, participantId: int, numbers: LottoNumbers}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function superzahl(): ?Superzahl
    {
        return $this->superzahl;
    }

    public function lotteryReference(): ?string
    {
        return $this->lotteryReference;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function submittedAt(): ?DateTimeImmutable
    {
        return $this->submittedAt;
    }
}
