<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\InvalidArgumentException;

/**
 * The nine winning classes of Lotto 6 aus 49.
 *
 *   1: 6 + Superzahl      2: 6      3: 5 + Superzahl    4: 5
 *   5: 4 + Superzahl      6: 4      7: 3 + Superzahl    8: 3
 *   9: 2 + Superzahl
 *
 * Anything below that wins nothing.
 */
final class WinningClass
{
    public const MIN = 1;
    public const MAX = 9;

    public function __construct(private int $value)
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new InvalidArgumentException(
                sprintf('Winning class %d is outside %d-%d', $value, self::MIN, self::MAX)
            );
        }
    }

    /**
     * Derives the class from a row's hits. Returns null when nothing was won -
     * two hits without the Superzahl, or fewer than two hits.
     */
    public static function fromMatch(int $matchedNumbers, bool $superzahlMatched): ?self
    {
        if ($matchedNumbers < 0 || $matchedNumbers > LottoNumbers::COUNT) {
            throw new InvalidArgumentException("Matched numbers out of range: $matchedNumbers");
        }

        return match (true) {
            $matchedNumbers === 6 && $superzahlMatched => new self(1),
            $matchedNumbers === 6 => new self(2),
            $matchedNumbers === 5 && $superzahlMatched => new self(3),
            $matchedNumbers === 5 => new self(4),
            $matchedNumbers === 4 && $superzahlMatched => new self(5),
            $matchedNumbers === 4 => new self(6),
            $matchedNumbers === 3 && $superzahlMatched => new self(7),
            $matchedNumbers === 3 => new self(8),
            $matchedNumbers === 2 && $superzahlMatched => new self(9),
            default => null,
        };
    }

    public function value(): int
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
