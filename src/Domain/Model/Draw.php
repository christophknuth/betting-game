<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

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
     * Records what the ticket won in this draw.
     *
     * @param array<string, mixed> $winningClasses
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
