<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Event\MemberAdded;
use BettingGame\Domain\Event\PayoutDistributed;
use BettingGame\Domain\Event\TippYearCreated;
use BettingGame\Domain\Event\TippYearStatusChanged;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Model\TippYear;
use BettingGame\Domain\ValueObject\TippYearStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TippYearTest extends TestCase
{
    private function year(): TippYear
    {
        return TippYear::create(
            1,
            'Tippjahr 2026',
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-12-31'),
            1.20
        );
    }

    private function closedYear(): TippYear
    {
        $year = $this->year();
        $year->start();
        $year->close();
        $year->releaseEvents();

        return $year;
    }

    public function testCreatingRecordsAnEventAndStartsPlanned(): void
    {
        $year = $this->year();

        self::assertSame(TippYearStatus::PLANNED, $year->status()->value());

        $events = $year->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(TippYearCreated::class, $events[0]);
        self::assertSame('2026-01-01', $events[0]->toArray()['start_date']);
    }

    public function testEndDateMustBeAfterStartDate(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        TippYear::create(
            1,
            'Broken',
            new DateTimeImmutable('2026-12-31'),
            new DateTimeImmutable('2026-01-01'),
            1.20
        );
    }

    public function testCostPerRowMustBePositive(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        TippYear::create(
            1,
            'Free',
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-12-31'),
            0.0
        );
    }

    public function testTheLifecycleGoesPlannedRunningClosed(): void
    {
        $year = $this->year();
        $year->releaseEvents();

        $year->start();
        self::assertTrue($year->status()->isRunning());
        self::assertTrue($year->acceptsTickets());

        $year->close();
        self::assertTrue($year->status()->isClosed());
        self::assertFalse($year->acceptsTickets(), 'a closed year takes no more tickets');

        $events = $year->releaseEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(TippYearStatusChanged::class, $events[0]);
    }

    public function testAPlannedYearCannotBeClosedDirectly(): void
    {
        $year = $this->year();

        $this->expectException(BusinessRuleViolationException::class);
        $year->close();
    }

    public function testAddingAMemberRecordsAnEvent(): void
    {
        $year = $this->year();
        $year->releaseEvents();

        $year->addMember(7);

        $events = $year->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MemberAdded::class, $events[0]);
        self::assertSame(7, $events[0]->toArray()['participant_id']);
    }

    public function testDistributingRecordsTheSharesAndClosesTheYear(): void
    {
        $year = $this->closedYear();

        $year->distribute(300.0, 3, 100.0, [
            ['participant_id' => 1, 'amount' => 100.0],
            ['participant_id' => 2, 'amount' => 100.0],
            ['participant_id' => 3, 'amount' => 100.0],
        ], 'admin');

        self::assertTrue($year->status()->isDistributed());

        $events = $year->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(PayoutDistributed::class, $events[0]);

        $payload = $events[0]->toArray();
        self::assertSame(300.0, $payload['total_winnings']);
        self::assertSame(100.0, $payload['share_per_participant']);
        self::assertCount(3, $payload['shares']);
    }

    public function testOnlyAClosedYearCanBeDistributed(): void
    {
        $year = $this->year();
        $year->start();

        $this->expectException(BusinessRuleViolationException::class);
        $year->distribute(100.0, 1, 100.0, [['participant_id' => 1, 'amount' => 100.0]]);
    }

    public function testDistributingTwiceIsRejected(): void
    {
        $year = $this->closedYear();
        $year->distribute(100.0, 1, 100.0, [['participant_id' => 1, 'amount' => 100.0]]);

        $this->expectException(BusinessRuleViolationException::class);
        $year->distribute(100.0, 1, 100.0, [['participant_id' => 1, 'amount' => 100.0]]);
    }

    public function testDistributingNeedsAtLeastOneParticipant(): void
    {
        $year = $this->closedYear();

        $this->expectException(BusinessRuleViolationException::class);
        $year->distribute(100.0, 0, 0.0, []);
    }

    public function testMembersCannotBeAddedAfterDistribution(): void
    {
        $year = $this->closedYear();
        $year->distribute(100.0, 1, 100.0, [['participant_id' => 1, 'amount' => 100.0]]);

        $this->expectException(BusinessRuleViolationException::class);
        $year->addMember(9);
    }
}
