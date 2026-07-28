<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\InvalidArgumentException;

/**
 * The Superzahl, 0 to 9.
 *
 * It comes from the ticket serial number, not from a bet row - every row on a
 * ticket shares it. That is why it lives on Ticket and Draw, never on BetRow.
 */
final class Superzahl
{
    public const MIN = 0;
    public const MAX = 9;

    public function __construct(private int $value)
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new InvalidArgumentException(
                sprintf('Superzahl %d is outside %d-%d', $value, self::MIN, self::MAX)
            );
        }
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
