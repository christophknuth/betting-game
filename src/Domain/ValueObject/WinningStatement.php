<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\BusinessRuleViolationException;

/**
 * What the lottery statement says about one draw, before it is applied to a
 * ticket.
 *
 * Two shapes are accepted, and exactly one of them per draw:
 *
 * - **One figure for the Spielauftrag.** What the whole ticket won. Nothing is
 *   known about the classes, so the amount is spread over the rows that won.
 * - **The amount one row of a class was paid** - the Quote, class by class.
 *   This is the shape the published statement has, and the interesting one:
 *   which rows achieved which class is something the system works out from the
 *   ticket's own row snapshots. Reading it off the statement by hand and
 *   multiplying is exactly the arithmetic that should not be done by a person.
 *
 * A statement is not yet money: how much the amounts per row come to depends on
 * how many rows of *this* ticket landed in each class, and that is only known
 * once the draw has been evaluated. `settle()` is that step, and `DrawWinnings`
 * is the result.
 *
 * Sending a total **and** amounts per class is refused. The total follows from
 * the amounts, so a second figure beside them is either the same number twice
 * or a contradiction, and there is no way to tell which from the outside.
 */
final class WinningStatement
{
    /**
     * @param list<array{winningClass: int, amountPerRow: float}> $amountsPerRow
     */
    private function __construct(
        private ?float $total,
        private array $amountsPerRow
    ) {
    }

    /**
     * @param list<array{winningClass: int, amountPerRow: float}> $amountsPerRow
     */
    public static function of(?float $total, array $amountsPerRow = []): self
    {
        if ($amountsPerRow === []) {
            if ($total === null) {
                throw new BusinessRuleViolationException(
                    'Record either the ticket total or the amounts per winning class'
                );
            }

            return new self(self::nonNegative($total), []);
        }

        if ($total !== null) {
            throw new BusinessRuleViolationException(
                'The ticket total follows from the amounts per winning class and is not recorded with them'
            );
        }

        return new self(null, self::validated($amountsPerRow));
    }

    /**
     * The money, once it is known how many rows achieved each class.
     *
     * @param array<int, int> $rowsPerClass winning class => rows of the ticket in it
     */
    public function settle(array $rowsPerClass): DrawWinnings
    {
        if ($this->total !== null) {
            return DrawWinnings::ofTotal($this->total);
        }

        return DrawWinnings::perClass($this->amountsPerRow, $rowsPerClass);
    }

    /**
     * @param list<array{winningClass: int, amountPerRow: float}> $amountsPerRow
     *
     * @return list<array{winningClass: int, amountPerRow: float}>
     */
    private static function validated(array $amountsPerRow): array
    {
        $seen = [];

        foreach ($amountsPerRow as $entry) {
            // Constructed for the check alone: a class outside 1-9 is not a
            // winning class, and WinningClass is where that is decided.
            new WinningClass($entry['winningClass']);

            if (isset($seen[$entry['winningClass']])) {
                throw new BusinessRuleViolationException(
                    sprintf('Winning class %d is listed twice', $entry['winningClass'])
                );
            }

            $seen[$entry['winningClass']] = true;

            self::nonNegative($entry['amountPerRow']);
        }

        return $amountsPerRow;
    }

    private static function nonNegative(float $amount): float
    {
        if ($amount < 0.0) {
            throw new BusinessRuleViolationException('A winning amount cannot be negative');
        }

        return $amount;
    }
}
