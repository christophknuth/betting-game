<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Exception\InvalidArgumentException;
use BettingGame\Domain\ValueObject\EvenSplit;
use PHPUnit\Framework\TestCase;

final class EvenSplitTest extends TestCase
{
    public function testAnExactSplitGivesEqualShares(): void
    {
        self::assertSame([50.0, 50.0], EvenSplit::of(100.00, 2));
        self::assertSame([25.0, 25.0, 25.0, 25.0], EvenSplit::of(100.00, 4));
    }

    public function testTheRemainderGoesOnTheFirstShare(): void
    {
        self::assertSame([33.34, 33.33, 33.33], EvenSplit::of(100.00, 3));
    }

    public function testNothingIsLostOrInvented(): void
    {
        foreach ([100.00, 123.45, 0.01, 999.99, 7.77] as $amount) {
            foreach ([1, 2, 3, 7, 13] as $parts) {
                $shares = EvenSplit::of($amount, $parts);

                self::assertSame(
                    (int) round($amount * 100),
                    (int) round(array_sum($shares) * 100),
                    "$amount over $parts parts must add back up exactly"
                );
            }
        }
    }

    public function testASingleShareGetsEverything(): void
    {
        self::assertSame([123.45], EvenSplit::of(123.45, 1));
    }

    public function testSplittingNothingGivesZeroes(): void
    {
        self::assertSame([0.0, 0.0, 0.0], EvenSplit::of(0.0, 3));
    }

    public function testAnAmountSmallerThanTheShareCount(): void
    {
        // One cent over three: the first participant gets it, nobody else does
        self::assertSame([0.01, 0.0, 0.0], EvenSplit::of(0.01, 3));
    }

    public function testThereHasToBeAtLeastOneShare(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EvenSplit::of(100.00, 0);
    }
}
