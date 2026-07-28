<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Event\DrawRecorded;
use BettingGame\Domain\Event\DrawWinningsRecorded;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Model\Draw;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DrawTest extends TestCase
{
    private function draw(): Draw
    {
        return Draw::record(
            1,
            5,
            new DateTimeImmutable('2026-03-07'),
            new LottoNumbers([3, 12, 19, 27, 33, 45]),
            new Superzahl(4)
        );
    }

    public function testRecordingADrawStoresNumbersAndSuperzahl(): void
    {
        $draw = $this->draw();

        self::assertSame(Draw::DRAWN, $draw->status());
        self::assertSame([3, 12, 19, 27, 33, 45], $draw->numbers()?->toArray());
        self::assertSame(4, $draw->superzahl()?->value());

        $events = $draw->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(DrawRecorded::class, $events[0]);
        self::assertSame('2026-03-07', $events[0]->toArray()['draw_date']);
    }

    public function testEvaluatingCountsMatchesAndDerivesTheWinningClass(): void
    {
        $draw = $this->draw();

        // Four hits plus the matching Superzahl is class 5
        $result = $draw->evaluate(new LottoNumbers([3, 12, 19, 27, 40, 49]), new Superzahl(4));

        self::assertSame(4, $result['matchedNumbers']);
        self::assertTrue($result['superzahlMatched']);
        self::assertSame(5, $result['winningClass']);
    }

    public function testAWrongSuperzahlDropsTheClass(): void
    {
        $draw = $this->draw();

        $result = $draw->evaluate(new LottoNumbers([3, 12, 19, 27, 40, 49]), new Superzahl(7));

        self::assertFalse($result['superzahlMatched']);
        self::assertSame(6, $result['winningClass'], 'four hits without the Superzahl is class 6');
    }

    public function testTwoHitsWithoutSuperzahlWinNothing(): void
    {
        $draw = $this->draw();

        $result = $draw->evaluate(new LottoNumbers([3, 12, 40, 41, 42, 43]), new Superzahl(7));

        self::assertSame(2, $result['matchedNumbers']);
        self::assertNull($result['winningClass']);
    }

    public function testATicketWithoutSuperzahlNeverMatchesIt(): void
    {
        $draw = $this->draw();

        $result = $draw->evaluate(new LottoNumbers([3, 12, 19, 27, 33, 45]), null);

        self::assertSame(6, $result['matchedNumbers']);
        self::assertFalse($result['superzahlMatched']);
        self::assertSame(2, $result['winningClass'], 'six hits without the Superzahl is class 2');
    }

    public function testRecordingWinningsMarksTheDrawEvaluated(): void
    {
        $draw = $this->draw();
        $draw->releaseEvents();

        $draw->recordWinnings(11, 1234.50, [['winningClass' => 5, 'amount' => 1234.50]]);

        self::assertSame(Draw::EVALUATED, $draw->status());

        $events = $draw->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(DrawWinningsRecorded::class, $events[0]);
        self::assertSame(1234.50, $events[0]->toArray()['total_amount']);
    }

    public function testANegativeWinningAmountIsRejected(): void
    {
        $draw = $this->draw();

        $this->expectException(BusinessRuleViolationException::class);
        $draw->recordWinnings(11, -1.0);
    }

    public function testAScheduledDrawCannotBeEvaluated(): void
    {
        $draw = Draw::fromProjection(
            1,
            5,
            new DateTimeImmutable('2026-03-07'),
            null,
            null,
            Draw::SCHEDULED,
            null,
            0
        );

        $this->expectException(BusinessRuleViolationException::class);
        $draw->evaluate(new LottoNumbers([1, 2, 3, 4, 5, 6]), new Superzahl(1));
    }
}
