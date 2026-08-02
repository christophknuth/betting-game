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
use BettingGame\Application\Query\GetBetPeriodsQuery;
use BettingGame\Application\Query\GetBetRowQuery;
use BettingGame\Application\Query\GetDrawsQuery;
use BettingGame\Application\Query\GetFeesQuery;
use BettingGame\Application\Query\GetMembershipsQuery;
use BettingGame\Application\Query\GetParticipantFeesQuery;
use BettingGame\Application\Query\GetPayoutShareQuery;
use BettingGame\Application\Query\GetTippYearsQuery;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Model\Fee;
use DateTimeImmutable;

/**
 * The read side (B-01 to B-05 plus the administrator's overviews), against a
 * year that has actually been played: two members, quarterly periods, two
 * tickets and an evaluated draw.
 */
final class QueryTest extends ApplicationTestCase
{
    private int $tippYearId;
    private int $q1;
    private int $q2;
    private int $januaryTicketId;

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

        $q1 = $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($this->tippYearId, 'Q1 2026', '2026-01-01', '2026-03-31')
        );
        $q2 = $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($this->tippYearId, 'Q2 2026', '2026-04-01', '2026-06-30')
        );
        self::assertNotNull($q1->resourceId);
        self::assertNotNull($q2->resourceId);
        $this->q1 = $q1->resourceId;
        $this->q2 = $q2->resourceId;

        $this->addMember()->handle(new AddMemberCommand($this->tippYearId, 7));
        $this->assignBetRow()->handle(new AssignBetRowCommand(7, $this->q1, [3, 12, 19, 27, 33, 45]));
        $this->assignBetRow()->handle(new AssignBetRowCommand(7, $this->q2, [10, 20, 30, 40, 41, 42]));

        $this->startTippYear($this->tippYearId);

        // January: only Anna is a member
        $january = $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-01-01', '2026-01-31', 9, 7, 'LOT-2026-01')
        );
        self::assertNotNull($january->resourceId);
        $this->januaryTicketId = $january->resourceId;

        // Ben joins in February
        $this->addMember()->handle(new AddMemberCommand($this->tippYearId, 8));
        $this->assignBetRow()->handle(new AssignBetRowCommand(8, $this->q1, [1, 2, 3, 4, 5, 6]));

        $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-02-01', '2026-02-28', 8, 7, 'LOT-2026-02')
        );

        $draw = $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2026-01-07', [3, 12, 19, 27, 40, 41], 7)
        );
        self::assertNotNull($draw->resourceId);
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($draw->resourceId, 123.45));

        // A second draw the ticket did not win anything in
        $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2026-01-10', [1, 2, 3, 4, 5, 6], 0)
        );
    }

    // --- B-01 ---

    public function testTheRunningRowIsTheOneForTodaysPeriod(): void
    {
        $data = $this->getBetRow()
            ->handle(new GetBetRowQuery(7), new DateTimeImmutable('2026-02-15'))
            ->toArray();

        self::assertSame([3, 12, 19, 27, 33, 45], $data['numbers']);
        self::assertSame('Q1 2026', $data['betPeriod']['name']);
        self::assertSame('Tippjahr 2026', $data['betPeriod']['tippYearName']);
        self::assertSame('2026-04-01', $data['changeableFrom'], 'the row stands until Q2 begins');
        self::assertSame(2, $data['ticketCount'], 'it went on the January and February tickets');
    }

    public function testAnExplicitPeriodOverridesToday(): void
    {
        $data = $this->getBetRow()
            ->handle(new GetBetRowQuery(7, $this->q2), new DateTimeImmutable('2026-02-15'))
            ->toArray();

        self::assertSame([10, 20, 30, 40, 41, 42], $data['numbers']);
        self::assertSame('Q2 2026', $data['betPeriod']['name']);
        self::assertNull($data['changeableFrom'], 'no period is planned after Q2');
        self::assertSame(0, $data['ticketCount'], 'no ticket has started in Q2 yet');
    }

    public function testNoRowForThePeriodIsNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->getBetRow()->handle(new GetBetRowQuery(8, $this->q2));
    }

    // --- B-02 ---

    public function testMembershipShowsTheTicketsTheOwnRowMissed(): void
    {
        $data = $this->getMemberships()->handle(new GetMembershipsQuery(8))->toArray();

        self::assertCount(1, $data['memberships']);
        $membership = $data['memberships'][0];

        self::assertSame('Tippjahr 2026', $membership['tippYearName']);
        self::assertSame('active', $membership['status']);
        self::assertCount(2, $membership['tickets']);

        $byId = [];
        foreach ($membership['tickets'] as $ticket) {
            $byId[$ticket['ticketId']] = $ticket['ownRowIncluded'];
        }

        self::assertFalse($byId[$this->januaryTicketId], 'Ben joined in February');
        self::assertCount(1, array_filter($byId));
    }

    public function testAMemberFromTheStartIsOnEveryTicket(): void
    {
        $data = $this->getMemberships()->handle(new GetMembershipsQuery(7))->toArray();

        $tickets = $data['memberships'][0]['tickets'];
        self::assertCount(2, $tickets);

        foreach ($tickets as $ticket) {
            self::assertTrue($ticket['ownRowIncluded']);
        }
    }

    // --- B-03 ---

    public function testOwnFeesCarryTheTicketPeriodAndASummary(): void
    {
        $data = $this->getParticipantFees()->handle(new GetParticipantFeesQuery(7))->toArray();

        self::assertCount(2, $data['fees'], 'January and February');
        self::assertSame('2026-02-01', $data['fees'][0]['periodStart'], 'newest first');

        // 1 row x 9 draws x 1.20 in January, 2 rows x 8 x 1.20 split in two in February
        self::assertSame(10.80, $data['fees'][1]['amount']);
        self::assertSame(9.60, $data['fees'][0]['amount']);
        self::assertSame(20.40, $data['summary']['totalCharged']);
        self::assertSame(20.40, $data['summary']['totalOpen']);
        self::assertSame(2, $data['summary']['openCount']);
    }

    public function testThePaidFeeLeavesTheOpenTotal(): void
    {
        $fee = $this->fees->findByParticipantAndTicket(7, $this->januaryTicketId);
        self::assertNotNull($fee);

        $this->recordFeePayment()->handle(
            new RecordFeePaymentCommand($fee->id(), Fee::PAID, null, 'cash', null, 'admin')
        );

        $data = $this->getParticipantFees()->handle(new GetParticipantFeesQuery(7))->toArray();

        self::assertSame(20.40, $data['summary']['totalCharged']);
        self::assertSame(9.60, $data['summary']['totalOpen']);
        self::assertSame(1, $data['summary']['openCount']);
    }

    public function testTheSummaryFollowsTheFilter(): void
    {
        $data = $this->getParticipantFees()
            ->handle(new GetParticipantFeesQuery(7, null, 'paid'))
            ->toArray();

        self::assertSame([], $data['fees']);
        self::assertSame(0.0, $data['summary']['totalCharged']);
    }

    // --- B-04 ---

    public function testBeforeTheDistributionThereIsOnlyAProvisionalAmount(): void
    {
        $data = $this->getPayoutShare()->handle(new GetPayoutShareQuery(7, $this->tippYearId))->toArray();

        self::assertNull($data['amount'], 'nothing is owed until the payout is booked');
        self::assertSame(123.45, $data['totalWinnings']);
        self::assertSame(2, $data['participantCount']);
        self::assertSame(61.73, $data['provisionalAmount']);
        self::assertSame('running', $data['tippYearStatus']);
        self::assertNull($data['distributedAt']);
    }

    public function testAfterTheDistributionTheAmountIsReal(): void
    {
        $this->closeTippYear($this->tippYearId);
        $this->distributePayout()->handle(
            new DistributePayoutCommand($this->tippYearId, true, null, 'admin')
        );

        $anna = $this->getPayoutShare()->handle(new GetPayoutShareQuery(7, $this->tippYearId))->toArray();
        $ben = $this->getPayoutShare()->handle(new GetPayoutShareQuery(8, $this->tippYearId))->toArray();

        self::assertSame(61.73, $anna['amount']);
        self::assertSame(61.72, $ben['amount']);
        self::assertNull($anna['provisionalAmount'], 'the interim figure is gone once it is real');
        self::assertSame('open', $anna['paymentStatus']);
        self::assertSame('distributed', $anna['tippYearStatus']);
        self::assertNotNull($anna['distributedAt']);
    }

    public function testTheProvisionalAmountMatchesWhatIsLaterBooked(): void
    {
        $before = $this->getPayoutShare()->handle(new GetPayoutShareQuery(7, $this->tippYearId))->toArray();

        $this->closeTippYear($this->tippYearId);
        $this->distributePayout()->handle(new DistributePayoutCommand($this->tippYearId, true));

        $after = $this->getPayoutShare()->handle(new GetPayoutShareQuery(7, $this->tippYearId))->toArray();

        self::assertSame($before['provisionalAmount'], $after['amount'], 'the figure must not jump');
    }

    // --- B-05 ---

    public function testDrawsCarryTheWholeTicketsWinnings(): void
    {
        $data = $this->getDraws()->handle(new GetDrawsQuery($this->tippYearId))->toArray();

        self::assertCount(2, $data['draws']);
        self::assertSame(123.45, $data['totalWinnings']);

        $evaluated = $data['draws'][1];
        self::assertSame('2026-01-07', $evaluated['drawDate']);
        self::assertSame([3, 12, 19, 27, 40, 41], $evaluated['numbers']);
        self::assertSame(7, $evaluated['superzahl']);
        self::assertSame('evaluated', $evaluated['status']);
        self::assertSame(123.45, $evaluated['ticket']['totalAmount']);
        self::assertSame(4, $evaluated['ticket']['bestMatch']['matchedNumbers']);
        self::assertTrue($evaluated['ticket']['bestMatch']['superzahlMatched']);
    }

    public function testADrawWithoutWinningsShowsItsRowsButNoAmount(): void
    {
        $data = $this->getDraws()->handle(new GetDrawsQuery($this->tippYearId))->toArray();

        $unevaluated = $data['draws'][0];
        self::assertSame('2026-01-10', $unevaluated['drawDate']);

        // B-24: the ticket took part whether or not anyone has recorded what it
        // won - but null is not zero, and the amount is still unknown.
        self::assertNotNull($unevaluated['ticket']);
        self::assertNull($unevaluated['ticket']['totalAmount']);
        self::assertNotSame([], $unevaluated['ticket']['rows']);
    }

    public function testTheRowsOfTheTicketAreShownWithWhatTheyAchieved(): void
    {
        $data = $this->getDraws()->handle(new GetDrawsQuery($this->tippYearId))->toArray();

        // Drawn on 2026-01-07: 3, 12, 19, 27, 40, 41 with Superzahl 7. The
        // January ticket carries only Anna's row - Ben joined in February.
        $rows = $data['draws'][1]['ticket']['rows'];
        self::assertCount(1, $rows);

        $anna = $rows[0];
        self::assertSame('Anna', $anna['displayName']);
        self::assertSame([3, 12, 19, 27, 33, 45], $anna['numbers'], 'the snapshot, not the current row');
        self::assertSame(4, $anna['matchedNumbers']);
        self::assertTrue($anna['superzahlMatched']);
        self::assertSame(5, $anna['winningClass']);
        self::assertSame(123.45, $anna['amount'], 'the only winning row takes the whole attribution');
    }

    public function testARowThatWonNothingIsListedAllTheSame(): void
    {
        $data = $this->getDraws()->handle(new GetDrawsQuery($this->tippYearId))->toArray();

        // 2026-01-10 drew 1, 2, 3, 4, 5, 6 with Superzahl 0: Anna's row shares
        // exactly the 3 with it, and the ticket's Superzahl is 7.
        $row = $data['draws'][0]['ticket']['rows'][0];

        self::assertSame(1, $row['matchedNumbers']);
        self::assertFalse($row['superzahlMatched']);
        self::assertNull($row['winningClass'], 'one hit is no class - but the row was evaluated');
        self::assertSame(0.0, $row['amount']);
    }

    public function testFilteringToWinningDrawsKeepsTheYearTotal(): void
    {
        $data = $this->getDraws()
            ->handle(new GetDrawsQuery($this->tippYearId, null, true))
            ->toArray();

        self::assertCount(1, $data['draws']);
        self::assertSame(
            123.45,
            $data['totalWinnings'],
            'a filtered list must not make the year look smaller'
        );
    }

    public function testFilteringByStatus(): void
    {
        $data = $this->getDraws()
            ->handle(new GetDrawsQuery($this->tippYearId, 'drawn'))
            ->toArray();

        self::assertCount(1, $data['draws']);
        self::assertSame('drawn', $data['draws'][0]['status']);
    }

    // --- Admin overviews ---

    public function testTippYearsComeWithTheirCounts(): void
    {
        $data = $this->getTippYears()->handle(new GetTippYearsQuery())->toArray();

        self::assertCount(1, $data['tippYears']);
        $year = $data['tippYears'][0];

        self::assertSame('Tippjahr 2026', $year['name']);
        self::assertSame(2, $year['memberCount']);
        self::assertSame(2, $year['ticketCount']);
        self::assertSame(2, $year['drawCount']);
        self::assertSame(123.45, $year['totalWinnings']);
        self::assertSame(1.20, $year['ticketCostPerRow']);
    }

    public function testTippYearsCanBeFilteredByStatus(): void
    {
        self::assertCount(
            1,
            $this->getTippYears()->handle(new GetTippYearsQuery('running'))->toArray()['tippYears']
        );
        self::assertCount(
            0,
            $this->getTippYears()->handle(new GetTippYearsQuery('closed'))->toArray()['tippYears']
        );
    }

    public function testBetPeriodsReportHowManyRowsTheyHold(): void
    {
        $data = $this->getBetPeriods()->handle(new GetBetPeriodsQuery($this->tippYearId))->toArray();

        self::assertCount(2, $data['betPeriods']);
        self::assertSame('Q1 2026', $data['betPeriods'][0]['name']);
        self::assertSame(2, $data['betPeriods'][0]['betRowCount'], 'Anna and Ben');
        self::assertSame(1, $data['betPeriods'][1]['betRowCount'], 'only Anna has a Q2 row');
    }

    public function testTheFeeLedgerNamesWhoOwes(): void
    {
        $data = $this->getFees()->handle(new GetFeesQuery($this->tippYearId))->toArray();

        self::assertCount(3, $data['fees'], 'Anna twice, Ben once');

        $names = array_unique(array_column($data['fees'], 'displayName'));
        sort($names);
        self::assertSame(['Anna', 'Ben'], $names);
    }

    public function testTheFeeLedgerFiltersByStatus(): void
    {
        self::assertCount(
            3,
            $this->getFees()->handle(new GetFeesQuery(null, null, 'open'))->toArray()['fees']
        );
        self::assertCount(
            0,
            $this->getFees()->handle(new GetFeesQuery(null, null, 'paid'))->toArray()['fees']
        );
    }

    public function testQueryingAnUnknownTippYear(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->getDraws()->handle(new GetDrawsQuery(999));
    }
}
