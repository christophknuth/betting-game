<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\InvalidArgumentException;

/**
 * Six distinct numbers from 1 to 49.
 *
 * Always stored sorted ascending, so two rows with the same numbers in a
 * different order are the same row.
 */
final class LottoNumbers
{
    public const COUNT = 6;
    public const MIN = 1;
    public const MAX = 49;

    /** @var list<int> */
    private array $numbers;

    /**
     * @param list<int> $numbers
     */
    public function __construct(array $numbers)
    {
        if (count($numbers) !== self::COUNT) {
            throw new InvalidArgumentException(
                sprintf('A bet row needs exactly %d numbers, got %d', self::COUNT, count($numbers))
            );
        }

        foreach ($numbers as $number) {
            if ($number < self::MIN || $number > self::MAX) {
                throw new InvalidArgumentException(
                    sprintf('Number %d is outside %d-%d', $number, self::MIN, self::MAX)
                );
            }
        }

        if (count(array_unique($numbers)) !== self::COUNT) {
            throw new InvalidArgumentException('Numbers must be distinct');
        }

        sort($numbers);

        $this->numbers = $numbers;
    }

    /**
     * @param array<int|string, mixed> $numbers
     */
    public static function fromMixed(array $numbers): self
    {
        $ints = [];

        foreach ($numbers as $number) {
            if (is_int($number)) {
                $ints[] = $number;
                continue;
            }

            if (is_string($number) && preg_match('/^\d+$/', $number) === 1) {
                $ints[] = (int) $number;
                continue;
            }

            throw new InvalidArgumentException('Numbers must be integers');
        }

        return new self($ints);
    }

    /** @return list<int> */
    public function toArray(): array
    {
        return $this->numbers;
    }

    /**
     * How many of these numbers appear in the drawn numbers.
     */
    public function matchCount(self $drawn): int
    {
        return count(array_intersect($this->numbers, $drawn->numbers));
    }

    public function equals(self $other): bool
    {
        return $this->numbers === $other->numbers;
    }

    public function __toString(): string
    {
        return implode(' - ', array_map(static fn (int $n): string => sprintf('%02d', $n), $this->numbers));
    }
}
