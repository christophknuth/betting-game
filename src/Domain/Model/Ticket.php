<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\TicketSubmitted;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
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
        DateTimeImmutable $periodEnd,
        int $drawCount,
        float $ticketCostPerRow,
        array $rows,
        ?Superzahl $superzahl = null,
        ?string $lotteryReference = null,
        float $processingFee = 0.0
    ): self {
        if ($rows === []) {
            throw new BusinessRuleViolationException('A ticket needs at least one bet row');
        }

        if ($drawCount < 1) {
            throw new BusinessRuleViolationException('A ticket must cover at least one draw');
        }

        if ($periodEnd <= $periodStart) {
            throw new BusinessRuleViolationException('Period end must be after period start');
        }

        if ($processingFee < 0.0) {
            throw new BusinessRuleViolationException('A processing fee cannot be negative');
        }

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
     * @param list<array{betRowId: int, participantId: int, numbers: LottoNumbers}> $rows
     */
    public static function fromProjection(
        int $id,
        int $tippYearId,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        int $drawCount,
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
