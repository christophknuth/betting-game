<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\DrawCorrected;
use BettingGame\Domain\Event\DrawRecorded;
use BettingGame\Domain\Event\DrawWinningsRecorded;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use BettingGame\Domain\ValueObject\WinningClass;
use DateTimeImmutable;

/**
 * One lottery draw.
 *
 * The draw is the result - there is no separate result entity. Winnings are
 * recorded afterwards and belong to the ticket as a whole.
 */
final class Draw
{
    use RecordsEvents;

    public const SCHEDULED = 'scheduled';
    public const DRAWN = 'drawn';
    public const EVALUATED = 'evaluated';

    private function __construct(
        private int $id,
        private int $tippYearId,
        private DateTimeImmutable $drawDate,
        private ?LottoNumbers $numbers,
        private ?Superzahl $superzahl,
        private string $status,
        private ?DateTimeImmutable $recordedAt
    ) {
    }

    public static function record(
        int $id,
        int $tippYearId,
        DateTimeImmutable $drawDate,
        LottoNumbers $numbers,
        Superzahl $superzahl
    ): self {
        $draw = new self(
            $id,
            $tippYearId,
            $drawDate,
            $numbers,
            $superzahl,
            self::DRAWN,
            new DateTimeImmutable()
        );

        $draw->recordEvent(new DrawRecorded(
            (string) $id,
            $tippYearId,
            $drawDate->format('Y-m-d'),
            $numbers->toArray(),
            $superzahl->value()
        ));

        return $draw;
    }

    /**
     * Rehydrates from the read model without recording events.
     */
    public static function fromProjection(
        int $id,
        int $tippYearId,
        DateTimeImmutable $drawDate,
        ?LottoNumbers $numbers,
        ?Superzahl $superzahl,
        string $status,
        ?DateTimeImmutable $recordedAt,
        int $version
    ): self {
        $draw = new self($id, $tippYearId, $drawDate, $numbers, $superzahl, $status, $recordedAt);
        $draw->markCommitted($version);

        return $draw;
    }

    /**
     * B-28: puts a mistyped draw right.
     *
     * **Only while nothing has been booked against it.** Once the winnings are
     * recorded the draw is not a set of numbers any more but the basis of the
     * fees and the year's total, and changing the numbers underneath that would
     * silently rewrite what everybody already saw. `evaluated` is therefore
     * where correcting stops - the way back is to record the winnings again,
     * which is a decision with a figure attached rather than a typo.
     *
     * The date is correctable too, and deliberately so: it decides which ticket
     * played, so a draw entered under the wrong date belongs to the wrong slip
     * entirely. Whether the new date is still inside the tipp year, and whether
     * a draw already exists for it, is checked outside - the first needs the
     * year, the second is the unique key's business.
     */
    public function correct(DateTimeImmutable $drawDate, LottoNumbers $numbers, Superzahl $superzahl): void
    {
        if ($this->status === self::EVALUATED) {
            throw new BusinessRuleViolationException(
                'This draw has been evaluated and cannot be corrected. '
                . 'Record its winnings again if the figures were wrong.'
            );
        }

        $unchanged = $drawDate->format('Y-m-d') === $this->drawDate->format('Y-m-d')
            && $this->numbers !== null && $numbers->equals($this->numbers)
            && $this->superzahl !== null && $superzahl->equals($this->superzahl);

        if ($unchanged) {
            throw new BusinessRuleViolationException('The corrected draw is identical to the current one');
        }

        $previousDate = $this->drawDate;
        $previousNumbers = $this->numbers;
        $previousSuperzahl = $this->superzahl;

        $this->drawDate = $drawDate;
        $this->numbers = $numbers;
        $this->superzahl = $superzahl;
        $this->status = self::DRAWN;
        $this->version++;

        $this->recordEvent(new DrawCorrected(
            (string) $this->id,
            $drawDate->format('Y-m-d'),
            $numbers->toArray(),
            $superzahl->value(),
            $previousDate->format('Y-m-d'),
            $previousNumbers?->toArray() ?? [],
            $previousSuperzahl?->value()
        ));
    }

    /**
     * Records what the ticket won in this draw.
     *
     * @param list<array<string, mixed>> $winningClasses optional breakdown per winning class
     */
    public function recordWinnings(int $ticketId, float $totalAmount, array $winningClasses = []): void
    {
        if ($this->numbers === null) {
            throw new BusinessRuleViolationException('The draw has no numbers yet');
        }

        if ($totalAmount < 0.0) {
            throw new BusinessRuleViolationException('A winning amount cannot be negative');
        }

        $this->status = self::EVALUATED;
        $this->version++;

        $this->recordEvent(new DrawWinningsRecorded(
            (string) $this->id,
            $ticketId,
            $totalAmount,
            $winningClasses
        ));
    }

    /**
     * Evaluates one bet row against this draw.
     *
     * @return array{matchedNumbers: int, superzahlMatched: bool, winningClass: int|null}
     */
    public function evaluate(LottoNumbers $rowNumbers, ?Superzahl $ticketSuperzahl): array
    {
        if ($this->numbers === null || $this->superzahl === null) {
            throw new BusinessRuleViolationException('The draw has not been recorded yet');
        }

        $matched = $rowNumbers->matchCount($this->numbers);
        $superzahlMatched = $ticketSuperzahl !== null && $ticketSuperzahl->equals($this->superzahl);

        return [
            'matchedNumbers' => $matched,
            'superzahlMatched' => $superzahlMatched,
            'winningClass' => WinningClass::fromMatch($matched, $superzahlMatched)?->value(),
        ];
    }

    public function id(): int
    {
        return $this->id;
    }

    public function tippYearId(): int
    {
        return $this->tippYearId;
    }

    public function drawDate(): DateTimeImmutable
    {
        return $this->drawDate;
    }

    public function numbers(): ?LottoNumbers
    {
        return $this->numbers;
    }

    public function superzahl(): ?Superzahl
    {
        return $this->superzahl;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function recordedAt(): ?DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
