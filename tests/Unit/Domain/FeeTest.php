<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Event\FeeCharged;
use BettingGame\Domain\Event\FeePaymentRecorded;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Model\Fee;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FeeTest extends TestCase
{
    private function charge(float $amount = 10.80): Fee
    {
        return Fee::charge(1, 7, 3, $amount, new DateTimeImmutable('2026-01-31'));
    }

    public function testChargingRecordsAnEvent(): void
    {
        $fee = $this->charge();

        $events = $fee->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(FeeCharged::class, $events[0]);
        self::assertSame('fee.charged', $events[0]->eventType());
        self::assertSame('fee', $events[0]->aggregateType());
    }

    public function testAFeeStartsOpen(): void
    {
        $fee = $this->charge();

        self::assertTrue($fee->isOpen());
        self::assertSame(Fee::OPEN, $fee->status());
        self::assertNull($fee->paidAt());
        self::assertFalse($fee->isPersisted());
    }

    public function testTheAmountIsRoundedToCents(): void
    {
        $fee = Fee::charge(1, 7, 3, 10.8049, new DateTimeImmutable('2026-01-31'));

        self::assertSame(10.80, $fee->amount());
    }

    public function testAFeeMustBePositive(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        $this->charge(0.0);
    }

    public function testMarkingPaidRecordsWhoBookedItAndWhen(): void
    {
        $fee = $this->charge();
        $fee->releaseEvents();

        $fee->markPaid('bank transfer', 'admin', new DateTimeImmutable('2026-01-20 10:00:00'));

        self::assertSame(Fee::PAID, $fee->status());
        self::assertFalse($fee->isOpen());
        self::assertSame('bank transfer', $fee->paymentMethod());
        self::assertSame('admin', $fee->bookedBy());
        self::assertSame('2026-01-20 10:00:00', $fee->paidAt()?->format('Y-m-d H:i:s'));

        $events = $fee->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(FeePaymentRecorded::class, $events[0]);
        self::assertSame('paid', $events[0]->toArray()['payment_status']);
    }

    public function testTheSameFeeCannotBeSettledTwice(): void
    {
        $fee = $this->charge();
        $fee->markPaid('cash', 'admin');

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('already paid');
        $fee->markPaid('cash', 'admin');
    }

    public function testAWaivedFeeCannotThenBePaid(): void
    {
        $fee = $this->charge();
        $fee->waive('hardship', 'admin');

        self::assertSame(Fee::WAIVED, $fee->status());
        self::assertSame('hardship', $fee->note());
        self::assertNull($fee->paidAt(), 'waiving is not a payment');

        $this->expectException(BusinessRuleViolationException::class);
        $fee->markPaid('cash', 'admin');
    }

    public function testWaivingNeedsAReason(): void
    {
        $fee = $this->charge();

        $this->expectException(BusinessRuleViolationException::class);
        $fee->waive('   ', 'admin');
    }

    public function testSettlingBumpsTheVersion(): void
    {
        $fee = Fee::fromProjection(
            1,
            7,
            3,
            10.80,
            new DateTimeImmutable('2026-01-31'),
            Fee::OPEN,
            null,
            null,
            null,
            null,
            1
        );

        self::assertSame(1, $fee->originalVersion());
        self::assertTrue($fee->isPersisted(), 'it came from the read model');

        $fee->markPaid('cash', 'admin');

        self::assertSame(2, $fee->version());
        self::assertSame(1, $fee->originalVersion(), 'the loaded version stays the append reference');
    }

    public function testMarkCommittedMovesTheAppendReferenceForward(): void
    {
        $fee = $this->charge();

        self::assertSame(0, $fee->originalVersion());

        $fee->markCommitted(1);

        self::assertSame(1, $fee->originalVersion(), 'a second save must expect the version it just wrote');
        self::assertSame(1, $fee->version());
        self::assertTrue($fee->isPersisted());
    }
}
