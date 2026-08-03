<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Application;

use BettingGame\Application\Command\AddMemberCommand;
use BettingGame\Application\Command\AssignBetRowCommand;
use BettingGame\Application\Command\CreateBetPeriodCommand;
use BettingGame\Application\Command\CreateTippYearCommand;
use BettingGame\Application\Command\DistributePayoutCommand;
use BettingGame\Application\Command\RecordDrawCommand;
use BettingGame\Application\Command\RecordDrawWinningsCommand;
use BettingGame\Application\Command\RecordFeePaymentCommand;
use BettingGame\Application\Command\SubmitTicketCommand;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\DuplicateEntryException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Exception\InvalidArgumentException;
use BettingGame\Domain\Model\Fee;
use BettingGame\Domain\ValueObject\DrawSchedule;

/**
 * The rejections the user stories call for - every one of these is a 409 or a
 * 404 once the HTTP layer maps it.
 */
final class CommandRulesTest extends ApplicationTestCase
{
    private int $tippYearId;
    private int $betPeriodId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->givenParticipant(7, 'Anna');
        $this->givenParticipant(8, 'Ben');

        $year = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2026', '2026-01-01', '2026-12-31', 1.20)
        );
        self::assertNotNull($year->resourceId);
        $this->tippYearId = $year->resourceId;

        $period = $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($this->tippYearId, '2026 gesamt', '2026-01-01', '2026-12-31')
        );
        self::assertNotNull($period->resourceId);
        $this->betPeriodId = $period->resourceId;
    }

    // --- B-10 ---

    public function testTippYearsMustNotOverlap(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/overlaps/');

        $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2026 overlapping', '2026-06-01', '2027-05-31', 1.20)
        );
    }

    public function testANonOverlappingYearIsFine(): void
    {
        $second = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2027', '2027-01-01', '2027-12-31', 1.30)
        );

        self::assertNotNull($second->resourceId);
    }

    // --- B-14 ---

    public function testABetPeriodMustStayInsideItsTippYear(): void
    {
        // A fresh year without periods, so nothing can overlap and the
        // containment rule is the only one that can fire.
        $next = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2027', '2027-01-01', '2027-12-31', 1.20)
        );
        self::assertNotNull($next->resourceId);

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/not inside the tipp year/');

        $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($next->resourceId, 'Ragt heraus', '2027-12-01', '2028-01-31')
        );
    }

    public function testBetPeriodsMustNotOverlap(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/overlaps/');

        $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($this->tippYearId, 'Q1 2026', '2026-01-01', '2026-03-31')
        );
    }

    public function testABetPeriodNeedsAnExistingTippYear(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand(999, 'Nirgendwo', '2026-01-01', '2026-03-31')
        );
    }

    // --- B-11 ---

    public function testAParticipantJoinsAYearOnlyOnce(): void
    {
        $this->addMember()->handle(new AddMemberCommand($this->tippYearId, 7));

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/already a member/');
        $this->addMember()->handle(new AddMemberCommand($this->tippYearId, 7));
    }

    public function testAnUnknownParticipantCannotJoin(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->addMember()->handle(new AddMemberCommand($this->tippYearId, 999));
    }

    // --- B-06 ---

    public function testASecondRowForThePeriodNeedsAReason(): void
    {
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $this->betPeriodId, [3, 12, 19, 27, 33, 45])
        );

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/already has a row/');
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $this->betPeriodId, [1, 2, 3, 4, 5, 6])
        );
    }

    public function testReplacingWithTheSameNumbersIsRejected(): void
    {
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $this->betPeriodId, [3, 12, 19, 27, 33, 45])
        );

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/identical/');
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $this->betPeriodId, [45, 33, 27, 19, 12, 3], 'no real change')
        );
    }

    public function testTheBetPeriodHasToExist(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, 999, [3, 12, 19, 27, 33, 45])
        );
    }

    // --- B-12 ---

    public function testATicketNeedsARunningTippYear(): void
    {
        $this->addMember()->handle(new AddMemberCommand($this->tippYearId, 7));
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $this->betPeriodId, [3, 12, 19, 27, 33, 45])
        );

        // The year is still `planned`
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/while the tipp year runs/');
        $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-01-01', 4, DrawSchedule::BOTH)
        );
    }

    public function testATicketWithoutAnyRowIsRejected(): void
    {
        $this->startTippYear($this->tippYearId);

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/No bet row is valid/');
        $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-01-01', 4, DrawSchedule::BOTH)
        );
    }

    public function testOnlyOneTicketPerMonth(): void
    {
        $this->addMember()->handle(new AddMemberCommand($this->tippYearId, 7));
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $this->betPeriodId, [3, 12, 19, 27, 33, 45])
        );
        $this->startTippYear($this->tippYearId);

        $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-01-01', 4, DrawSchedule::BOTH)
        );

        $this->expectException(DuplicateEntryException::class);
        $this->expectExceptionMessageMatches('/uk_year_period/');
        $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-01-01', 4, DrawSchedule::BOTH)
        );
    }

    // --- B-08 ---

    public function testADrawDateExistsOnlyOnce(): void
    {
        $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2026-01-07', [3, 12, 19, 27, 40, 41], 7)
        );

        $this->expectException(DuplicateEntryException::class);
        $this->expectExceptionMessageMatches('/uk_draw_date/');
        $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2026-01-07', [1, 2, 3, 4, 5, 6], 3)
        );
    }

    public function testADrawOutsideTheTippYearIsRejected(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/outside the tipp year/');
        $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2027-01-07', [3, 12, 19, 27, 40, 41], 7)
        );
    }

    // --- B-09 ---

    public function testWinningsNeedATicketCoveringTheDraw(): void
    {
        $draw = $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2026-01-07', [3, 12, 19, 27, 40, 41], 7)
        );
        self::assertNotNull($draw->resourceId);

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/No ticket covers/');
        $this->recordDrawWinnings()->handle(
            new RecordDrawWinningsCommand($draw->resourceId, 123.45)
        );
    }

    public function testWinningsForAnUnknownDraw(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand(999, 10.0));
    }

    // --- B-07 ---

    public function testASettledFeeCannotBeReopened(): void
    {
        $feeId = $this->givenAnOpenFee();

        $this->recordFeePayment()->handle(
            new RecordFeePaymentCommand($feeId, Fee::PAID, null, 'cash', null, 'admin')
        );

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/cannot be reopened/');
        $this->recordFeePayment()->handle(new RecordFeePaymentCommand($feeId, Fee::OPEN));
    }

    public function testAFeeIsNotPaidTwice(): void
    {
        $feeId = $this->givenAnOpenFee();

        $this->recordFeePayment()->handle(new RecordFeePaymentCommand($feeId, Fee::PAID));

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/already paid/');
        $this->recordFeePayment()->handle(new RecordFeePaymentCommand($feeId, Fee::PAID));
    }

    public function testWaivingNeedsANote(): void
    {
        $feeId = $this->givenAnOpenFee();

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/requires a reason/');
        $this->recordFeePayment()->handle(new RecordFeePaymentCommand($feeId, Fee::WAIVED));
    }

    public function testSettingAnOpenFeeToOpenIsAccepted(): void
    {
        $feeId = $this->givenAnOpenFee();

        $result = $this->recordFeePayment()->handle(new RecordFeePaymentCommand($feeId, Fee::OPEN));

        self::assertSame('accepted', $result->status);
    }

    // --- B-13 ---

    public function testDistributionHasToBeConfirmed(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/has to be confirmed/');
        $this->distributePayout()->handle(new DistributePayoutCommand($this->tippYearId, false));
    }

    public function testOnlyAClosedYearIsDistributed(): void
    {
        $this->addMember()->handle(new AddMemberCommand($this->tippYearId, 7));
        $this->startTippYear($this->tippYearId);

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/Only a closed tipp year/');
        $this->distributePayout()->handle(new DistributePayoutCommand($this->tippYearId, true));
    }

    public function testAYearIsDistributedOnlyOnce(): void
    {
        $this->addMember()->handle(new AddMemberCommand($this->tippYearId, 7));
        $this->startTippYear($this->tippYearId);
        $this->closeTippYear($this->tippYearId);

        $this->distributePayout()->handle(new DistributePayoutCommand($this->tippYearId, true));

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/already been distributed/');
        $this->distributePayout()->handle(new DistributePayoutCommand($this->tippYearId, true));
    }

    public function testAYearWithoutMembersCannotBeDistributed(): void
    {
        $this->startTippYear($this->tippYearId);
        $this->closeTippYear($this->tippYearId);

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/no members/');
        $this->distributePayout()->handle(new DistributePayoutCommand($this->tippYearId, true));
    }

    // --- B-18: the tipp year lifecycle ---

    public function testOnlyOneTippYearRunsAtATime(): void
    {
        $this->startTippYear($this->tippYearId);

        $other = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2027', '2027-01-01', '2027-12-31', 1.20)
        );
        self::assertNotNull($other->resourceId);

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/is still running/');
        $this->startTippYear($other->resourceId);
    }

    public function testAnotherYearMayRunOnceTheFirstOneLeavesRunning(): void
    {
        $this->startTippYear($this->tippYearId);
        $this->closeTippYear($this->tippYearId);

        $other = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2027', '2027-01-01', '2027-12-31', 1.20)
        );
        self::assertNotNull($other->resourceId);

        $this->startTippYear($other->resourceId);

        $running = $this->tippYears->findRunning();
        self::assertNotNull($running);
        self::assertSame($other->resourceId, $running->id());
    }

    /**
     * The rule is about *other* years. Re-running the one that already runs is
     * refused for a different reason, and it must not be mistaken for the
     * single-running rule.
     */
    public function testTheRunningYearIsNotBlockedByItself(): void
    {
        $this->startTippYear($this->tippYearId);

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessageMatches('/already running/');
        $this->startTippYear($this->tippYearId);
    }

    public function testAYearMayBeReopenedAfterBeingClosed(): void
    {
        $this->startTippYear($this->tippYearId);
        $this->closeTippYear($this->tippYearId);

        // B-18 allows every path on purpose: a year closed a week too early has
        // to be reopenable, and that correction belongs in the event history.
        $this->startTippYear($this->tippYearId);

        $year = $this->tippYears->find($this->tippYearId);
        self::assertNotNull($year);
        self::assertTrue($year->status()->isRunning());
    }

    public function testAnUnknownStatusIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->changeTippYearStatus($this->tippYearId, 'paused');
    }

    public function testChangingTheStatusOfAnUnknownYearIsNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->changeTippYearStatus(9999, 'running');
    }

    private function givenAnOpenFee(): int
    {
        $this->addMember()->handle(new AddMemberCommand($this->tippYearId, 7));
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $this->betPeriodId, [3, 12, 19, 27, 33, 45])
        );
        $this->startTippYear($this->tippYearId);

        $ticket = $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-01-01', 4, DrawSchedule::BOTH)
        );
        self::assertNotNull($ticket->resourceId);

        return $this->fees->findByTicket($ticket->resourceId)[0]->id();
    }
}
