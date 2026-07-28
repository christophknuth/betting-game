<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Domain\Model\TippYear;
use BettingGame\Infrastructure\Persistence\Row;
use BettingGame\Infrastructure\Persistence\TippYearRepository;
use DateTimeImmutable;

final class TippYearRepositoryTest extends IntegrationTestCase
{
    private TippYearRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new TippYearRepository($this->db, $this->eventStore);
    }

    private function givenYear(int $id = 1, string $name = 'Tippjahr 2026'): TippYear
    {
        $year = TippYear::create(
            $id,
            $name,
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-12-31'),
            1.20
        );

        $this->repository->save($year);

        return $year;
    }

    public function testSavingWritesProjectionAndStream(): void
    {
        $this->givenYear();

        $loaded = $this->repository->find(1);

        self::assertNotNull($loaded);
        self::assertSame('Tippjahr 2026', $loaded->name());
        self::assertSame(1.20, $loaded->ticketCostPerRow());
        self::assertSame('planned', $loaded->status()->value());
        self::assertSame(1, $this->eventStore->getStreamVersion('tipp_year-1'));
    }

    public function testTheStreamCanBeReadBack(): void
    {
        $this->givenYear();

        $events = $this->eventStore->getStream('tipp_year-1');

        self::assertCount(1, $events);
        self::assertSame('tipp_year.created', $events[0]->eventType());
    }

    public function testStatusChangesAppendToTheSameStream(): void
    {
        $this->givenYear();

        $loaded = $this->repository->find(1);
        self::assertNotNull($loaded);
        $loaded->start();
        $this->repository->save($loaded);

        self::assertSame(2, $this->eventStore->getStreamVersion('tipp_year-1'));

        $reloaded = $this->repository->find(1);
        self::assertNotNull($reloaded);
        self::assertSame('running', $reloaded->status()->value());
        self::assertSame(2, $reloaded->originalVersion(), 'the projection tracks the stream version');
    }

    public function testFindRunningIgnoresPlannedYears(): void
    {
        $this->givenYear();

        self::assertNull($this->repository->findRunning());

        $loaded = $this->repository->find(1);
        self::assertNotNull($loaded);
        $loaded->start();
        $this->repository->save($loaded);

        self::assertNotNull($this->repository->findRunning());
    }

    public function testFindCoveringMatchesBothEndsOfTheYear(): void
    {
        $this->givenYear();

        self::assertNotNull($this->repository->findCovering(new DateTimeImmutable('2026-01-01')));
        self::assertNotNull($this->repository->findCovering(new DateTimeImmutable('2026-12-31')));
        self::assertNull($this->repository->findCovering(new DateTimeImmutable('2025-12-31')));
    }

    public function testAddingAMemberProjectsAMembership(): void
    {
        $this->givenParticipant(7, 'Anna');
        $year = $this->givenYear();

        $year->addMember(7);
        $this->repository->save($year);

        self::assertSame([7], $this->repository->memberIds(1));
        self::assertTrue($this->repository->isMember(1, 7));
        self::assertFalse($this->repository->isMember(1, 8));
    }

    public function testMembershipsOfCarriesTheYearItBelongsTo(): void
    {
        $this->givenParticipant(7, 'Anna');
        $year = $this->givenYear();
        $year->addMember(7);
        $this->repository->save($year);

        $memberships = $this->repository->membershipsOf(7);

        self::assertCount(1, $memberships);
        self::assertSame('Tippjahr 2026', Row::string($memberships[0], 'tipp_year_name'));
        self::assertSame('active', Row::string($memberships[0], 'status'));
    }

    public function testDistributionProjectsPayoutAndShares(): void
    {
        $this->givenParticipant(7, 'Anna');
        $this->givenParticipant(8, 'Ben');

        $year = $this->givenYear();
        $year->start();
        $year->close();
        $this->repository->save($year);

        $reloaded = $this->repository->find(1);
        self::assertNotNull($reloaded);

        $reloaded->distribute(
            300.00,
            2,
            150.00,
            [
                ['participant_id' => 7, 'amount' => 150.00],
                ['participant_id' => 8, 'amount' => 150.00],
            ],
            'admin'
        );
        $this->repository->save($reloaded);

        $payout = $this->repository->findPayout(1);
        self::assertNotNull($payout);
        self::assertSame(300.00, Row::float($payout, 'total_winnings'));
        self::assertSame(2, Row::int($payout, 'participant_count'));

        $share = $this->repository->payoutShareOf(1, 7);
        self::assertNotNull($share);
        self::assertSame(150.00, Row::float($share, 'amount'));
        self::assertSame('open', Row::string($share, 'payment_status'));
    }

    public function testThereIsNoShareBeforeTheDistribution(): void
    {
        $this->givenParticipant(7);
        $this->givenYear();

        self::assertNull($this->repository->payoutShareOf(1, 7));
        self::assertNull($this->repository->findPayout(1));
    }

    public function testNextIdentityStartsAtOneAndThenFollowsTheMaximum(): void
    {
        self::assertSame(1, $this->repository->nextIdentity());

        $this->givenYear(1);

        self::assertSame(2, $this->repository->nextIdentity());
    }
}
