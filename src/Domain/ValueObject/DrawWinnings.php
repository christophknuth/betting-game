<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

/**
 * What a ticket won in one draw, settled.
 *
 * This is the result of applying a `WinningStatement` to the rows that took
 * part - never built from a request directly, which is why the amounts here are
 * taken as already validated.
 *
 * Where the statement gave an amount per row, the arithmetic is the one B-23
 * exists for:
 *
 *     total = Σ over the classes: amount for one row × rows of the ticket in it
 *
 * A class the ticket did not achieve contributes nothing and is kept all the
 * same. That the Quote was entered and produced nothing is a fact about this
 * draw, and dropping it would make the record unreadable next to the statement
 * it was typed from.
 */
final class DrawWinnings
{
    /**
     * @param list<array{winningClass: int, amountPerRow: float, rowCount: int, amount: float}> $classes
     */
    private function __construct(
        private float $total,
        private array $classes
    ) {
    }

    /** The statement gave one figure for the whole Spielauftrag. */
    public static function ofTotal(float $total): self
    {
        return new self($total, []);
    }

    /**
     * The statement gave what one row of each class was paid.
     *
     * @param list<array{winningClass: int, amountPerRow: float}> $amountsPerRow from a validated statement
     * @param array<int, int>                                     $rowsPerClass  class => rows of the ticket in it
     */
    public static function perClass(array $amountsPerRow, array $rowsPerClass): self
    {
        $classes = [];
        $totalCents = 0;

        foreach ($amountsPerRow as $entry) {
            $rowCount = $rowsPerClass[$entry['winningClass']] ?? 0;

            // Multiplied in cents rather than in floats: a Quote of 0.10 times
            // three rows is 0.30000000000000004 the other way round, and the
            // year's total is summed from these figures.
            $classCents = (int) round($entry['amountPerRow'] * 100) * $rowCount;
            $totalCents += $classCents;

            $classes[] = [
                'winningClass' => $entry['winningClass'],
                'amountPerRow' => $entry['amountPerRow'],
                'rowCount' => $rowCount,
                // Cast, not decoration: PHP divides two integers into an
                // integer where it comes out even, and 300 in a field declared
                // as an amount is a type nobody downstream expects.
                'amount' => (float) ($classCents / 100),
            ];
        }

        return new self((float) ($totalCents / 100), $classes);
    }

    /** What the whole ticket won - the figure the tipp year is summed from. */
    public function total(): float
    {
        return $this->total;
    }

    /**
     * What each class contributed, as the attribution consumes it.
     *
     * `WinningsDistribution` divides a class's amount among the rows in that
     * class, which for an amount of `rowCount × amountPerRow` hands every one of
     * them exactly the amount per row again. Feeding it the class total rather
     * than the Quote keeps one attribution path for both shapes of statement -
     * and for the events that were written before the Quote was asked for.
     *
     * @return list<array{winningClass: int, amount: float}>
     */
    public function breakdown(): array
    {
        return array_map(
            static fn (array $class): array => [
                'winningClass' => $class['winningClass'],
                'amount' => $class['amount'],
            ],
            $this->classes
        );
    }

    /**
     * The full picture per class - what was entered, how many rows it applied
     * to and what came of it. This is what goes into the event.
     *
     * @return list<array{winningClass: int, amountPerRow: float, rowCount: int, amount: float}>
     */
    public function classes(): array
    {
        return $this->classes;
    }
}
