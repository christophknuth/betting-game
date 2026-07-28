<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-09: record what the ticket won in a draw. */
final class RecordDrawWinningsCommand
{
    /**
     * @param list<array{winningClass: int, amount: float}> $winningClasses
     *     optional breakdown; when empty the total is spread over the rows that won
     */
    public function __construct(
        public readonly int $drawId,
        public readonly float $totalAmount,
        public readonly array $winningClasses = []
    ) {
    }
}
