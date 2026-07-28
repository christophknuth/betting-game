<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Event\BetRowAssigned;
use BettingGame\Domain\Event\BetRowReplaced;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Model\BetRow;
use BettingGame\Domain\ValueObject\LottoNumbers;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BetRowTest extends TestCase
{
    private function numbers(int ...$values): LottoNumbers
    {
        return new LottoNumbers($values === [] ? [3, 12, 19, 27, 33, 45] : array_values($values));
    }

    public function testAssigningRecordsAnEvent(): void
    {
        $row = BetRow::assign(1, 7, 12, $this->numbers());

        $events = $row->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(BetRowAssigned::class, $events[0]);
        self::assertSame('bet_row.assigned', $events[0]->eventType());
        self::assertSame('bet_row', $events[0]->aggregateType());
        self::assertSame([3, 12, 19, 27, 33, 45], $events[0]->toArray()['numbers']);
    }

    public function testAFreshRowStartsAtVersionZero(): void
    {
        $row = BetRow::assign(1, 7, 12, $this->numbers());

        self::assertSame(0, $row->version());
        self::assertSame(0, $row->originalVersion());
    }

    public function testReplacingRequiresAReason(): void
    {
        $row = BetRow::assign(1, 7, 12, $this->numbers());

        $this->expectException(BusinessRuleViolationException::class);
        $row->replace($this->numbers(1, 2, 3, 4, 5, 6), '   ');
    }

    public function testReplacingWithTheSameNumbersIsRejected(): void
    {
        $row = BetRow::assign(1, 7, 12, $this->numbers());

        $this->expectException(BusinessRuleViolationException::class);
        // Same numbers in a different order are the same row
        $row->replace($this->numbers(45, 33, 27, 19, 12, 3), 'typo');
    }

    public function testReplacingRecordsPreviousAndNewNumbers(): void
    {
        $row = BetRow::assign(1, 7, 12, $this->numbers());
        $row->releaseEvents();

        $row->replace($this->numbers(1, 2, 3, 4, 5, 6), 'wrong slip transcribed');

        $events = $row->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(BetRowReplaced::class, $events[0]);

        $payload = $events[0]->toArray();
        self::assertSame([3, 12, 19, 27, 33, 45], $payload['previous_numbers']);
        self::assertSame([1, 2, 3, 4, 5, 6], $payload['numbers']);
        self::assertSame('wrong slip transcribed', $payload['reason']);
    }

    public function testReplacingBumpsTheVersion(): void
    {
        $row = BetRow::fromProjection(
            1,
            7,
            12,
            $this->numbers(),
            new DateTimeImmutable('2026-01-05'),
            1
        );

        self::assertSame(1, $row->originalVersion());

        $row->replace($this->numbers(1, 2, 3, 4, 5, 6), 'correction');

        self::assertSame(2, $row->version());
        self::assertSame(1, $row->originalVersion(), 'the loaded version stays the append reference');
    }

    public function testReleasingEventsClearsThem(): void
    {
        $row = BetRow::assign(1, 7, 12, $this->numbers());

        self::assertCount(1, $row->releaseEvents());
        self::assertCount(0, $row->releaseEvents());
    }
}
