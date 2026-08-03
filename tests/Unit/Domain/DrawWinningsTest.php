<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\InvalidArgumentException;
use BettingGame\Domain\ValueObject\WinningStatement;
use PHPUnit\Framework\TestCase;

/**
 * B-23: the statement is read either as one sum for the ticket or class by
 * class, and what the ticket won follows from it.
 *
 * Class by class the figure is what *one* row of that class was paid. It only
 * becomes money together with the rows: how many of the ticket's rows landed in
 * each class is the second half of the multiplication, and this is where the
 * two halves meet.
 */
final class DrawWinningsTest extends TestCase
{
    public function testATotalOnItsOwnIsTheBookedFigure(): void
    {
        $winnings = WinningStatement::of(123.45)->settle([]);

        self::assertSame(123.45, $winnings->total());
        self::assertSame([], $winnings->breakdown(), 'nothing to attribute per class');
    }

    public function testAnAmountPerRowIsMultipliedByTheRowsInThatClass(): void
    {
        $winnings = WinningStatement::of(null, [['winningClass' => 5, 'amountPerRow' => 150.00]])
            ->settle([5 => 2]);

        self::assertSame(300.00, $winnings->total(), '2 rows x 150.00');
        self::assertSame([['winningClass' => 5, 'amount' => 300.00]], $winnings->breakdown());
    }

    public function testTheClassesAddUpToTheTicketsTotal(): void
    {
        $winnings = WinningStatement::of(null, [
            ['winningClass' => 5, 'amountPerRow' => 12.30],
            ['winningClass' => 8, 'amountPerRow' => 5.00],
        ])->settle([5 => 2, 8 => 3]);

        self::assertSame(39.60, $winnings->total(), '2 x 12.30 + 3 x 5.00');
    }

    public function testAClassNoRowReachedContributesNothingAndIsKept(): void
    {
        // Kept on purpose: that the amount was entered and produced nothing is
        // a fact about this draw, and the record has to read like the statement
        // it was typed from.
        $winnings = WinningStatement::of(null, [['winningClass' => 2, 'amountPerRow' => 500.00]])
            ->settle([5 => 2]);

        self::assertSame(0.0, $winnings->total());
        self::assertSame(
            [['winningClass' => 2, 'amountPerRow' => 500.00, 'rowCount' => 0, 'amount' => 0.0]],
            $winnings->classes()
        );
    }

    public function testTheMultiplicationIsDoneInCentsNotInFloats(): void
    {
        // 0.07 times three is 0.21000000000000002 in binary floating point, and
        // the year's total is summed from these figures.
        $winnings = WinningStatement::of(null, [['winningClass' => 8, 'amountPerRow' => 0.07]])
            ->settle([8 => 3]);

        self::assertSame(0.21, $winnings->total());
    }

    public function testWhatWasEnteredSurvivesNextToWhatCameOfIt(): void
    {
        $winnings = WinningStatement::of(null, [['winningClass' => 5, 'amountPerRow' => 12.30]])
            ->settle([5 => 2]);

        self::assertSame(
            [['winningClass' => 5, 'amountPerRow' => 12.30, 'rowCount' => 2, 'amount' => 24.60]],
            $winnings->classes()
        );
    }

    public function testATotalAlongsideTheClassesIsRejected(): void
    {
        // Which of the two would count is not for the system to guess, and the
        // amounts per class are the ones that can be checked against the rows.
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('follows from the amounts per winning class');

        WinningStatement::of(300.00, [['winningClass' => 5, 'amountPerRow' => 150.00]]);
    }

    public function testNeitherFigureIsRejected(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('either the ticket total or the amounts per winning class');

        WinningStatement::of(null);
    }

    public function testAClassListedTwiceIsRejected(): void
    {
        // Which of the two amounts would count is not for the system to guess -
        // and silently taking the last one would book half the statement.
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('Winning class 5 is listed twice');

        WinningStatement::of(null, [
            ['winningClass' => 5, 'amountPerRow' => 300.00],
            ['winningClass' => 5, 'amountPerRow' => 12.50],
        ]);
    }

    public function testANegativeTotalIsRejected(): void
    {
        $this->expectException(BusinessRuleViolationException::class);

        WinningStatement::of(-1.00);
    }

    public function testANegativeAmountPerRowIsRejected(): void
    {
        $this->expectException(BusinessRuleViolationException::class);

        WinningStatement::of(null, [['winningClass' => 5, 'amountPerRow' => -1.00]]);
    }

    public function testAClassOutsideOneToNineIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WinningStatement::of(null, [['winningClass' => 10, 'amountPerRow' => 5.00]]);
    }

    public function testAZeroTotalIsARecordedResultNotAMissingOne(): void
    {
        // The ticket won nothing on this draw, and that is worth booking: it
        // distinguishes an evaluated draw from one nobody has looked at.
        self::assertSame(0.0, WinningStatement::of(0.0)->settle([])->total());
    }
}
