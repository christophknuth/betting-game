<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\TicketSubmitted;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
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
        private ?DateTimeImmutable $submittedAt
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
        ?string $lotteryReference = null
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

        $totalCost = round(count($rows) * $drawCount * $ticketCostPerRow, 2);

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
            new DateTimeImmutable()
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
            $lotteryReference
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
        int $version
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
            $submittedAt
        );
        $ticket->markCommitted($version);

        return $ticket;
    }

    /**
     * The share one participant owes for this ticket.
     *
     * The cost is split evenly across the rows on the ticket, so everyone pays
     * the same regardless of when they joined the year.
     */
    public function feePerParticipant(): float
    {
        return round($this->totalCost / count($this->rows), 2);
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
