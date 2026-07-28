<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\InvalidArgumentException;

/**
 * Splits an amount of money into equal shares without losing a cent.
 *
 * Dividing in floats and rounding each share separately either creates or
 * destroys money: 100.00 across 3 gives 33.33 three times, and one cent
 * disappears. So the split happens in whole cents and the remainder goes onto
 * the first share - the convention the payout in B-13 states explicitly, and
 * the same one the per-row winnings in B-09 need.
 */
final class EvenSplit
{
    /**
     * @return list<float> shares in the requested order, summing exactly to $amount
     */
    public static function of(float $amount, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('An amount cannot be split into fewer than one share');
        }

        $totalCents = (int) round($amount * 100);
        $baseCents = intdiv($totalCents, $parts);
        $remainder = $totalCents - $baseCents * $parts;

        $shares = [];
        for ($i = 0; $i < $parts; $i++) {
            $cents = $baseCents + ($i === 0 ? $remainder : 0);
            // Cast explicitly: PHP's `/` hands back an int when the division
            // comes out exact, so 5000/100 would be int(50) among floats.
            $shares[] = (float) $cents / 100;
        }

        return $shares;
    }
}
