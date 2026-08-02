<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\InvalidArgumentException;
use BettingGame\Domain\ValueObject\DrawWinnings;
use PHPUnit\Framework\TestCase;

/**
 * B-23: the statement is read either as one sum for the ticket or class by
 * class, and both have to arrive at the same booked figure.
 *
 * Money entered by hand, so the interesting cases are the ones where the two
 * shapes meet: a breakdown that accounts for only part of the ticket's total -
 * which is allowed and predates B-23 - one that claims more than it, and a sum
 * of cents that floating point would round away from the statement.
 */
final class DrawWinningsTest extends TestCase
{
    public function testATotalOnItsOwnIsTheBookedFigure(): void
    {
        $winnings = DrawWinnings::of(123.45);

        self::assertSame(123.45, $winnings->total());
        self::assertSame([], $winnings->breakdown(), 'nothing to attribute per class');
    }

    public function testABreakdownOnItsOwnAddsUpToTheTotal(): void
    {
        $winnings = DrawWinnings::of(null, [
            ['winningClass' => 5, 'amount' => 300.00],
            ['winningClass' => 8, 'amount' => 12.50],
        ]);

        self::assertSame(312.50, $winnings->total());
        self::assertCount(2, $winnings->breakdown());
    }

    public function testTheSumIsAddedInCentsNotInFloats(): void
    {
        // 0.1 + 0.2 is 0.30000000000000004 in binary floating point. Three
        // times 0.10 has to be exactly 0.30, or the year's total drifts.
        $winnings = DrawWinnings::of(null, [
            ['winningClass' => 7, 'amount' => 0.10],
            ['winningClass' => 8, 'amount' => 0.20],
        ]);

        self::assertSame(0.30, $winnings->total());
    }

    public function testATotalThatAgreesWithItsBreakdownIsAccepted(): void
    {
        $winnings = DrawWinnings::of(312.50, [
            ['winningClass' => 5, 'amount' => 300.00],
            ['winningClass' => 8, 'amount' => 12.50],
        ]);

        self::assertSame(312.50, $winnings->total());
    }

    public function testABreakdownMayAccountForOnlyPartOfTheTotal(): void
    {
        // Older than B-23 and deliberate: the ticket won 500, of which 300 is
        // attributable to class 5. The remaining 200 stays with the ticket -
        // it counts towards the year, but no row of this ticket can claim it.
        $winnings = DrawWinnings::of(500.00, [['winningClass' => 5, 'amount' => 300.00]]);

        self::assertSame(500.00, $winnings->total());
        self::assertCount(1, $winnings->breakdown());
    }

    public function testABreakdownClaimingMoreThanTheTotalIsRejected(): void
    {
        // A ticket cannot pay out more than it won, whichever of the two
        // figures the administrator mistyped.
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('add up to 312.50, more than the ticket total of 100.00');

        DrawWinnings::of(100.00, [
            ['winningClass' => 5, 'amount' => 300.00],
            ['winningClass' => 8, 'amount' => 12.50],
        ]);
    }

    public function testNeitherFigureIsRejected(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('either the ticket total or the amounts per winning class');

        DrawWinnings::of(null);
    }

    public function testAClassListedTwiceIsRejected(): void
    {
        // Which of the two amounts would count is not for the system to guess -
        // and silently taking the last one would book half the statement.
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('Winning class 5 is listed twice');

        DrawWinnings::of(null, [
            ['winningClass' => 5, 'amount' => 300.00],
            ['winningClass' => 5, 'amount' => 12.50],
        ]);
    }

    public function testANegativeTotalIsRejected(): void
    {
        $this->expectException(BusinessRuleViolationException::class);

        DrawWinnings::of(-1.00);
    }

    public function testANegativeClassAmountIsRejected(): void
    {
        $this->expectException(BusinessRuleViolationException::class);

        DrawWinnings::of(null, [['winningClass' => 5, 'amount' => -1.00]]);
    }

    public function testAClassOutsideOneToNineIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DrawWinnings::of(null, [['winningClass' => 10, 'amount' => 5.00]]);
    }

    public function testAZeroTotalIsARecordedResultNotAMissingOne(): void
    {
        // The ticket won nothing on this draw, and that is worth booking: it
        // distinguishes an evaluated draw from one nobody has looked at.
        self::assertSame(0.0, DrawWinnings::of(0.0)->total());
    }
}
