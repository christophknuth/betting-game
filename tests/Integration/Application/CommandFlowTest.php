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
use BettingGame\Domain\Model\Fee;
use BettingGame\Support\Row;

/**
 * One tipp year from creation to distribution, in the order an administrator
 * would actually work through it (B-10, B-14, B-11, B-06, B-12, B-08, B-09,
 * B-07, B-13).
 */
final class CommandFlowTest extends ApplicationTestCase
{
    public function testAWholeTippYear(): void
    {
        $this->givenParticipant(7, 'Anna');
        $this->givenParticipant(8, 'Ben');

        // B-10: the tipp year
        $year = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2026', '2026-01-01', '2026-12-31', 1.20)
        );
        $tippYearId = $year->resourceId;
        self::assertNotNull($tippYearId);
        self::assertSame('accepted', $year->status);

        // B-14: one period over the whole year - "one row per year"
        $period = $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($tippYearId, '2026 gesamt', '2026-01-01', '2026-12-31')
        );
        $betPeriodId = $period->resourceId;
        self::assertNotNull($betPeriodId);

        // B-11: the members
        $this->addMember()->handle(new AddMemberCommand($tippYearId, 7));
        $this->addMember()->handle(new AddMemberCommand($tippYearId, 8));
        self::assertSame([7, 8], $this->tippYears->memberIds($tippYearId));

        // B-06: their standing rows
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $betPeriodId, [3, 12, 19, 27, 33, 45])
        );
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(8, $betPeriodId, [1, 2, 3, 4, 5, 6])
        );

        $this->startTippYear($tippYearId);

        // B-12: the January ticket - 2 rows x 9 draws x 1.20
        $ticket = $this->submitTicket()->handle(
            new SubmitTicketCommand($tippYearId, '2026-01-01', '2026-01-31', 9, 7, 'LOT-2026-01')
        );
        $ticketId = $ticket->resourceId;
        self::assertNotNull($ticketId);

        $submitted = $this->tickets->find($ticketId);
        self::assertNotNull($submitted);
        self::assertSame(2, $submitted->rowCount());
        self::assertSame(21.60, $submitted->totalCost());

        // Each member owes half of it
        $fees = $this->fees->findByTicket($ticketId);
        self::assertCount(2, $fees);
        self::assertSame(10.80, $fees[0]->amount());
        self::assertSame(10.80, $fees[1]->amount());
        self::assertSame(21.60, $this->fees->openTotalOf(7) + $this->fees->openTotalOf(8));

        // B-08: a draw Anna gets four numbers and the Superzahl right
        $draw = $this->recordDraw()->handle(
            new RecordDrawCommand($tippYearId, '2026-01-07', [3, 12, 19, 27, 40, 41], 7)
        );
        $drawId = $draw->resourceId;
        self::assertNotNull($drawId);

        // B-09: what the ticket won
        $this->recordDrawWinnings()->handle(
            new RecordDrawWinningsCommand($drawId, 123.45)
        );

        self::assertSame(123.45, $this->draws->totalWinnings($tippYearId));

        // Only Anna's row won, so the whole amount sits on it
        $matches = $this->db->fetchAll(
            'SELECT m.*, br.participant_id
             FROM ticket_row_match m
             JOIN ticket_row tr ON tr.ticket_row_id = m.ticket_row_id
             JOIN bet_row br ON br.bet_row_id = tr.bet_row_id
             WHERE m.draw_id = ?
             ORDER BY br.participant_id',
            [$drawId]
        );

        self::assertCount(2, $matches);
        self::assertSame(7, Row::int($matches[0], 'participant_id'));
        self::assertSame(4, Row::int($matches[0], 'matched_numbers'));
        self::assertTrue(Row::bool($matches[0], 'superzahl_matched'));
        self::assertSame(123.45, Row::float($matches[0], 'amount'));

        self::assertSame(8, Row::int($matches[1], 'participant_id'));
        self::assertSame(1, Row::int($matches[1], 'matched_numbers'));
        self::assertSame(0.0, Row::float($matches[1], 'amount'), 'Ben won nothing');

        // B-07: Anna pays, Ben does not
        $this->recordFeePayment()->handle(
            new RecordFeePaymentCommand(
                $fees[0]->id(),
                Fee::PAID,
                '2026-01-20 10:00:00',
                'bank_transfer',
                null,
                'admin'
            )
        );

        self::assertSame(0.0, $this->fees->openTotalOf(7));
        self::assertSame(10.80, $this->fees->openTotalOf(8));

        // B-13: the annual distribution
        $this->closeTippYear($tippYearId);

        $this->distributePayout()->handle(
            new DistributePayoutCommand($tippYearId, true, null, 'admin')
        );

        $payout = $this->tippYears->findPayout($tippYearId);
        self::assertNotNull($payout);
        self::assertSame(123.45, Row::float($payout, 'total_winnings'));
        self::assertSame(2, Row::int($payout, 'participant_count'));

        // Evenly, regardless of who paid their fee or whose row won
        $anna = $this->tippYears->payoutShareOf($tippYearId, 7);
        $ben = $this->tippYears->payoutShareOf($tippYearId, 8);
        self::assertNotNull($anna);
        self::assertNotNull($ben);
        self::assertSame(61.73, Row::float($anna, 'amount'), 'the odd cent goes to the first share');
        self::assertSame(61.72, Row::float($ben, 'amount'));

        // Summed in cents: adding the two floats gives 123.44999999999999
        self::assertSame(
            12345,
            (int) round(Row::float($anna, 'amount') * 100) + (int) round(Row::float($ben, 'amount') * 100),
            'the shares add back up to the winnings exactly'
        );
    }

    public function testMonthlyPeriodsLetTheRowChangeEveryMonth(): void
    {
        $this->givenParticipant(7, 'Anna');

        $year = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2026', '2026-01-01', '2026-12-31', 1.20)
        );
        $tippYearId = $year->resourceId;
        self::assertNotNull($tippYearId);

        $january = $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($tippYearId, 'Januar 2026', '2026-01-01', '2026-01-31')
        );
        $february = $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($tippYearId, 'Februar 2026', '2026-02-01', '2026-02-28')
        );

        self::assertNotNull($january->resourceId);
        self::assertNotNull($february->resourceId);

        // A different row per period, and no replaceReason needed for either
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $january->resourceId, [3, 12, 19, 27, 33, 45])
        );
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $february->resourceId, [1, 2, 3, 4, 5, 6])
        );

        self::assertSame(
            2,
            $this->betPeriods->nextSequence($tippYearId) - 1,
            'the sequence follows the periods created so far'
        );

        $counts = $this->betPeriods->betRowCounts($tippYearId);
        self::assertSame(1, $counts[$january->resourceId]);
        self::assertSame(1, $counts[$february->resourceId]);
    }

    public function testCorrectingARowWithinARunningPeriod(): void
    {
        $this->givenParticipant(7, 'Anna');

        $year = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2026', '2026-01-01', '2026-12-31', 1.20)
        );
        self::assertNotNull($year->resourceId);

        $period = $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($year->resourceId, '2026 gesamt', '2026-01-01', '2026-12-31')
        );
        self::assertNotNull($period->resourceId);

        $assigned = $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $period->resourceId, [3, 12, 19, 27, 33, 45])
        );

        $replaced = $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $period->resourceId, [1, 2, 3, 4, 5, 6], 'wrong slip transcribed')
        );

        self::assertSame($assigned->resourceId, $replaced->resourceId, 'it is the same row, corrected');
        self::assertSame('Bet row replaced', $replaced->message);

        $row = $this->betRows->find((int) $assigned->resourceId);
        self::assertNotNull($row);
        self::assertSame([1, 2, 3, 4, 5, 6], $row->numbers()->toArray());

        // The correction is on the record, with its reason
        $events = $this->eventStore->getStream('bet_row-' . $assigned->resourceId);
        self::assertCount(2, $events);
        self::assertSame('bet_row.replaced', $events[1]->eventType());
        self::assertSame('wrong slip transcribed', $events[1]->toArray()['reason']);
    }
}
