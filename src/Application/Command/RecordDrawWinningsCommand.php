<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-09, B-23: record what the ticket won in a draw. */
final class RecordDrawWinningsCommand
{
    /**
     * @param float|null $totalAmount what the whole ticket won. Optional since
     *     B-23: a breakdown states the same figure class by class, and
     *     DrawWinnings adds it up rather than asking for it twice
     * @param list<array{winningClass: int, amount: float}> $winningClasses
     *     optional breakdown; when empty the total is spread over the rows that won
     */
    public function __construct(
        public readonly int $drawId,
        public readonly ?float $totalAmount,
        public readonly array $winningClasses = []
    ) {
    }
}
