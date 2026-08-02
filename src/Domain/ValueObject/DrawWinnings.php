<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\BusinessRuleViolationException;

/**
 * What a ticket won in one draw - stated as one figure, or class by class.
 *
 * B-23: the lottery statement comes in both shapes. Sometimes it is a single
 * sum for the Spielauftrag, sometimes it lists what each winning class paid.
 * Whoever has the detailed one should not have to add it up by hand, and
 * whoever has the sum should not have to invent a breakdown.
 *
 * Without a total, the breakdown becomes one: the class amounts are added up
 * here rather than by the person reading the statement.
 *
 * Sending both stays allowed and keeps its older meaning - the total is what
 * the ticket won, the breakdown says how much of it is attributable to named
 * classes, and the rest is winnings that no row of this ticket can claim. Only
 * a breakdown adding up to *more* than the total is refused: that describes a
 * ticket paying out more than it won.
 */
final class DrawWinnings
{
    /**
     * @param list<array{winningClass: int, amount: float}> $breakdown
     */
    private function __construct(
        private float $total,
        private array $breakdown
    ) {
    }

    /**
     * @param list<array{winningClass: int, amount: float}> $breakdown
     */
    public static function of(?float $total, array $breakdown = []): self
    {
        if ($breakdown === []) {
            if ($total === null) {
                throw new BusinessRuleViolationException(
                    'Record either the ticket total or the amounts per winning class'
                );
            }

            return new self(self::nonNegative($total), []);
        }

        $sum = self::sum($breakdown);

        if ($total === null) {
            return new self($sum, $breakdown);
        }

        // Half a cent of tolerance, because both figures arrive as floats: 0.1
        // plus 0.2 is not 0.3 in binary, and a hundredth of a cent apart is
        // that, not a disagreement about money.
        if ($sum > self::nonNegative($total) + 0.005) {
            throw new BusinessRuleViolationException(sprintf(
                'The winning classes add up to %.2f, more than the ticket total of %.2f',
                $sum,
                $total
            ));
        }

        // The stated total wins, and a breakdown below it is not a mistake: it
        // attributes part of the winnings to classes and leaves the rest with
        // the ticket, where no row can claim it.
        return new self($total, $breakdown);
    }

    /** What the whole ticket won - the figure the tipp year is summed from. */
    public function total(): float
    {
        return $this->total;
    }

    /** @return list<array{winningClass: int, amount: float}> */
    public function breakdown(): array
    {
        return $this->breakdown;
    }

    /**
     * @param list<array{winningClass: int, amount: float}> $breakdown
     */
    private static function sum(array $breakdown): float
    {
        $seen = [];
        $cents = 0;

        foreach ($breakdown as $entry) {
            // Constructed for the check alone: a class outside 1-9 is not a
            // winning class, and WinningClass is where that is decided.
            new WinningClass($entry['winningClass']);

            if (isset($seen[$entry['winningClass']])) {
                throw new BusinessRuleViolationException(
                    sprintf('Winning class %d is listed twice', $entry['winningClass'])
                );
            }

            $seen[$entry['winningClass']] = true;

            // Added in cents: summing floats and rounding at the end can land a
            // cent away from the statement the administrator is reading off.
            $cents += (int) round(self::nonNegative($entry['amount']) * 100);
        }

        return $cents / 100;
    }

    private static function nonNegative(float $amount): float
    {
        if ($amount < 0.0) {
            throw new BusinessRuleViolationException('A winning amount cannot be negative');
        }

        return $amount;
    }
}
