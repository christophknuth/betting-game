<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Application;

use BettingGame\Application\Command\AddMemberCommand;
use BettingGame\Application\Command\AssignBetRowCommand;
use BettingGame\Application\Command\CorrectDrawCommand;
use BettingGame\Application\Command\CreateBetPeriodCommand;
use BettingGame\Application\Command\CreateTippYearCommand;
use BettingGame\Application\Command\RecordDrawCommand;
use BettingGame\Application\Command\RecordDrawWinningsCommand;
use BettingGame\Application\Command\SubmitTicketCommand;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\DuplicateEntryException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Model\Draw;
use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Domain\ValueObject\DrawSchedule;
use BettingGame\Support\Row;

/**
 * B-28: a draw entered wrongly, put right.
 *
 * The interesting part is not the three columns changing - it is everything
 * hanging off them. The hits per row follow from the numbers, so they have to
 * be worked out again; the date decides which ticket played, so a correction
 * can move a draw onto another slip entirely; and once the winnings are booked,
 * none of it may move at all.
 */
final class CorrectDrawTest extends ApplicationTestCase
{
    private int $tippYearId;
    private int $ticketId;
    private int $drawId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->participants->save(Participant::create(1, null, new DisplayName('Anna'), true));

        $year = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2026', '2026-01-01', '2026-12-31', 1.20)
        );
        self::assertNotNull($year->resourceId);
        $this->tippYearId = $year->resourceId;

        $period = $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($this->tippYearId, 'Januar', '2026-01-01', '2026-01-31')
        );
        self::assertNotNull($period->resourceId);

        $this->addMember()->handle(new AddMemberCommand($this->tippYearId, 1));
        $this->assignBetRow()->handle(new AssignBetRowCommand(1, $period->resourceId, [3, 12, 19, 27, 33, 45]));

        $this->startTippYear($this->tippYearId);

        $ticket = $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-01-01', 4, DrawSchedule::BOTH, 4, 'LOS-0001')
        );
        self::assertNotNull($ticket->resourceId);
        $this->ticketId = $ticket->resourceId;

        // Four of Anna's six, and the Superzahl she plays - class 5
        $drawn = $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2026-01-07', [3, 12, 19, 27, 40, 41], 4)
        );
        self::assertNotNull($drawn->resourceId);
        $this->drawId = $drawn->resourceId;
    }

    public function testTheCorrectedNumbersReplaceTheOnesEntered(): void
    {
        $this->correctDraw()->handle(
            new CorrectDrawCommand($this->drawId, '2026-01-07', [3, 12, 19, 27, 33, 41], 4)
        );

        $draw = $this->db->fetchOne('SELECT * FROM draw WHERE draw_id = ?', [$this->drawId]);

        self::assertNotNull($draw);
        self::assertSame([3, 12, 19, 27, 33, 41], Row::json($draw, 'numbers'));
        self::assertSame(Draw::DRAWN, Row::string($draw, 'status'));
    }

    public function testTheRowsAreEvaluatedAgainstTheCorrectedNumbers(): void
    {
        self::assertSame(4, $this->matchedNumbers(), 'four hits before the correction');

        // The 41 should have been Anna's 33 - which makes it five
        $this->correctDraw()->handle(
            new CorrectDrawCommand($this->drawId, '2026-01-07', [3, 12, 19, 27, 33, 41], 4)
        );

        self::assertSame(5, $this->matchedNumbers(), 'the hits follow the numbers, they do not survive them');
    }

    public function testACorrectedDateMovesTheDrawToTheTicketThatActuallyCoversIt(): void
    {
        // February is outside the ticket's four weeks, so nothing covers the
        // corrected date and the evaluation has nothing to write.
        $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($this->tippYearId, 'Februar', '2026-02-01', '2026-02-28')
        );

        $result = $this->correctDraw()->handle(
            new CorrectDrawCommand($this->drawId, '2026-02-04', [3, 12, 19, 27, 40, 41], 4)
        );

        self::assertStringContainsString('no ticket covers it', $result->message);
        self::assertSame(
            [],
            $this->db->fetchAll('SELECT * FROM ticket_row_match WHERE draw_id = ?', [$this->drawId]),
            'the rows of the ticket it left must not stay behind as results of a draw they did not play'
        );
    }

    public function testADrawCannotBeMovedOutOfItsTippYear(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('outside the tipp year');

        $this->correctDraw()->handle(
            new CorrectDrawCommand($this->drawId, '2027-01-07', [3, 12, 19, 27, 40, 41], 4)
        );
    }

    public function testADrawCannotBeMovedOntoADayThatAlreadyHasOne(): void
    {
        $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2026-01-10', [1, 2, 3, 4, 5, 6], 1)
        );

        $this->expectException(DuplicateEntryException::class);

        $this->correctDraw()->handle(
            new CorrectDrawCommand($this->drawId, '2026-01-10', [3, 12, 19, 27, 40, 41], 4)
        );
    }

    public function testAnEvaluatedDrawIsRefused(): void
    {
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 25.00));

        $this->expectException(BusinessRuleViolationException::class);
        $this->expectExceptionMessage('cannot be corrected');

        $this->correctDraw()->handle(
            new CorrectDrawCommand($this->drawId, '2026-01-07', [1, 2, 3, 4, 5, 6], 4)
        );
    }

    public function testAnUnknownDrawIsNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->correctDraw()->handle(
            new CorrectDrawCommand(9999, '2026-01-07', [1, 2, 3, 4, 5, 6], 4)
        );
    }

    public function testARebuildReproducesTheCorrectedDraw(): void
    {
        $this->correctDraw()->handle(
            new CorrectDrawCommand($this->drawId, '2026-01-10', [3, 12, 19, 27, 33, 41], 7)
        );

        $before = $this->db->fetchAll('SELECT * FROM draw ORDER BY draw_id');
        $matchesBefore = $this->db->fetchAll(
            'SELECT ticket_row_id, matched_numbers, superzahl_matched, winning_class, amount
             FROM ticket_row_match WHERE draw_id = ? ORDER BY ticket_row_id',
            [$this->drawId]
        );

        $this->projections()->rebuildAll();

        self::assertSame($before, $this->db->fetchAll('SELECT * FROM draw ORDER BY draw_id'));
        self::assertSame($matchesBefore, $this->db->fetchAll(
            'SELECT ticket_row_id, matched_numbers, superzahl_matched, winning_class, amount
             FROM ticket_row_match WHERE draw_id = ? ORDER BY ticket_row_id',
            [$this->drawId]
        ));
    }

    /** How many of the drawn numbers the one row on the ticket hit. */
    private function matchedNumbers(): int
    {
        $row = $this->db->fetchOne(
            'SELECT m.matched_numbers
             FROM ticket_row_match m
             JOIN ticket_row tr ON tr.ticket_row_id = m.ticket_row_id
             WHERE m.draw_id = ? AND tr.ticket_id = ?',
            [$this->drawId, $this->ticketId]
        );

        self::assertNotNull($row, 'the row of the covering ticket has no evaluation at all');

        return Row::int($row, 'matched_numbers');
    }
}
