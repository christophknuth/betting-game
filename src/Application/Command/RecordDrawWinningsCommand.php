<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-09, B-23: record what the ticket won in a draw. */
final class RecordDrawWinningsCommand
{
    /**
     * @param float|null $totalAmount what the whole ticket won, for a statement
     *     that gives one figure. Left out where the classes are recorded: the
     *     total follows from them and is not entered twice
     * @param list<array{winningClass: int, amountPerRow: float}> $winningClasses
     *     what *one* row of each class was paid. How many rows of the ticket
     *     that applies to is the system's to work out, not the caller's
     */
    public function __construct(
        public readonly int $drawId,
        public readonly ?float $totalAmount,
        public readonly array $winningClasses = []
    ) {
    }
}
