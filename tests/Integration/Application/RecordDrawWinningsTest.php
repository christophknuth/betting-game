<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Application;

use BettingGame\Application\Command\AddMemberCommand;
use BettingGame\Application\Command\AssignBetRowCommand;
use BettingGame\Application\Command\CreateBetPeriodCommand;
use BettingGame\Application\Command\CreateTippYearCommand;
use BettingGame\Application\Command\RecordDrawCommand;
use BettingGame\Application\Command\RecordDrawWinningsCommand;
use BettingGame\Application\Command\SubmitTicketCommand;
use BettingGame\Domain\Model\Draw;
use BettingGame\Support\Row;

/**
 * What each row of the ticket achieved in a draw (B-22), and how the ticket's
 * winnings are attributed to those rows (B-09).
 *
 * Money, so it gets its own file: the amounts have to add back up to what the
 * administrator entered, whether or not a breakdown per winning class came
 * with it. The two stories share this fixture because they are two moments of
 * one calculation - the hits are known with the numbers, the amounts only with
 * the statement.
 */
final class RecordDrawWinningsTest extends ApplicationTestCase
{
    private int $tippYearId;
    private int $drawId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->givenParticipant(7, 'Anna');
        $this->givenParticipant(8, 'Ben');
        $this->givenParticipant(9, 'Cara');

        $year = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2026', '2026-01-01', '2026-12-31', 1.20)
        );
        self::assertNotNull($year->resourceId);
        $this->tippYearId = $year->resourceId;

        $period = $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($this->tippYearId, '2026 gesamt', '2026-01-01', '2026-12-31')
        );
        self::assertNotNull($period->resourceId);

        foreach ([7, 8, 9] as $participantId) {
            $this->addMember()->handle(new AddMemberCommand($this->tippYearId, $participantId));
        }

        // Drawn numbers are 3, 12, 19, 27, 40, 41 with Superzahl 7.
        // Anna and Ben both match four; Cara matches none.
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(7, $period->resourceId, [3, 12, 19, 27, 33, 45])
        );
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(8, $period->resourceId, [3, 12, 19, 27, 34, 46])
        );
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(9, $period->resourceId, [2, 4, 6, 8, 10, 14])
        );

        $this->startTippYear($this->tippYearId);

        $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-01-01', '2026-01-31', 9, 7, 'LOT-2026-01')
        );

        $draw = $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2026-01-07', [3, 12, 19, 27, 40, 41], 7)
        );
        self::assertNotNull($draw->resourceId);
        $this->drawId = $draw->resourceId;
    }

    /** @return array<int, array<string, mixed>> participant id => their match row */
    private function matchesByParticipant(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT m.*, br.participant_id
             FROM ticket_row_match m
             JOIN ticket_row tr ON tr.ticket_row_id = m.ticket_row_id
             JOIN bet_row br ON br.bet_row_id = tr.bet_row_id
             WHERE m.draw_id = ?',
            [$this->drawId]
        );

        $byParticipant = [];
        foreach ($rows as $row) {
            $byParticipant[Row::int($row, 'participant_id')] = $row;
        }

        return $byParticipant;
    }

    public function testRecordingTheDrawAlreadyEvaluatesEveryRow(): void
    {
        // No winnings have been recorded in setUp - only the draw
        $matches = $this->matchesByParticipant();

        self::assertCount(3, $matches, 'every row of the covering ticket, not only the winners');
        self::assertSame(4, Row::int($matches[7], 'matched_numbers'));
        self::assertSame(5, Row::int($matches[7], 'winning_class'), 'four plus the Superzahl');
        self::assertSame(0, Row::int($matches[9], 'matched_numbers'));
        self::assertNull($matches[9]['winning_class']);
    }

    public function testTheRowsCarryNoAmountUntilTheWinningsAreKnown(): void
    {
        foreach ($this->matchesByParticipant() as $match) {
            self::assertSame(0.0, Row::float($match, 'amount'));
        }

        // ... and the draw is not "evaluated" either. That status says the money
        // is booked, which is what B-13 sums the year from.
        $draw = $this->draws->find($this->drawId);
        self::assertNotNull($draw);
        self::assertSame(Draw::DRAWN, $draw->status());
    }

    public function testADrawWithoutACoveringTicketIsStillRecorded(): void
    {
        // February is outside the January ticket's period, so there is nothing
        // to evaluate against - which must not stop the draw being recorded.
        $draw = $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2026-02-04', [1, 2, 3, 4, 5, 6], 3)
        );

        self::assertNotNull($draw->resourceId);
        self::assertSame([], $this->draws->winningClassesOf($draw->resourceId));
    }

    public function testTheWinningsCatchUpADrawThatHadNoTicketYet(): void
    {
        $draw = $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2026-02-04', [3, 12, 19, 27, 40, 41], 7)
        );
        self::assertNotNull($draw->resourceId);

        // The ticket arrives after the draw - late, but it covers it
        $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-02-01', '2026-02-28', 4, 7, 'LOT-2026-02')
        );
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($draw->resourceId, 60.00));

        $classes = $this->draws->winningClassesOf($draw->resourceId);

        self::assertCount(1, $classes);
        self::assertSame(2, $classes[0]['rowCount'], 'the two four-hit rows found their draw after all');
    }

    public function testEveryRowIsEvaluatedNotJustTheWinners(): void
    {
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 100.00));

        $matches = $this->matchesByParticipant();

        self::assertCount(3, $matches);
        self::assertSame(4, Row::int($matches[7], 'matched_numbers'));
        self::assertSame(4, Row::int($matches[8], 'matched_numbers'));
        self::assertSame(0, Row::int($matches[9], 'matched_numbers'));
        self::assertNull($matches[9]['winning_class'], 'no match, no class');
    }

    public function testWithoutABreakdownTheTotalIsSplitOverTheWinningRows(): void
    {
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 100.00));

        $matches = $this->matchesByParticipant();

        // Two winners share 100.00; the odd cent is not in play here
        self::assertSame(50.0, Row::float($matches[7], 'amount'));
        self::assertSame(50.0, Row::float($matches[8], 'amount'));
        self::assertSame(0.0, Row::float($matches[9], 'amount'), 'a losing row gets nothing');
    }

    public function testAnOddAmountStillAddsBackUp(): void
    {
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 100.01));

        $matches = $this->matchesByParticipant();

        $cents = 0;
        foreach ([7, 8, 9] as $participantId) {
            $cents += (int) round(Row::float($matches[$participantId], 'amount') * 100);
        }

        self::assertSame(10001, $cents, 'nothing may be lost in the split');
    }

    public function testBothWinnersLandInClassFive(): void
    {
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 100.00));

        $matches = $this->matchesByParticipant();

        // Four numbers plus the Superzahl is class 5 - four without it is 6
        self::assertSame(5, Row::int($matches[7], 'winning_class'));
        self::assertSame(5, Row::int($matches[8], 'winning_class'));
    }

    public function testAnExplicitBreakdownSplitsTheClassAmountNotTheTotal(): void
    {
        $this->recordDrawWinnings()->handle(
            new RecordDrawWinningsCommand(
                $this->drawId,
                500.00,
                [['winningClass' => 5, 'amount' => 300.00]]
            )
        );

        $matches = $this->matchesByParticipant();

        // 300 for the class, over its two rows - the other 200 stays with the
        // ticket and is not attributed to any row.
        self::assertSame(150.0, Row::float($matches[7], 'amount'));
        self::assertSame(150.0, Row::float($matches[8], 'amount'));
        self::assertSame(0.0, Row::float($matches[9], 'amount'));
    }

    public function testABreakdownNamingAClassNobodyReachedPaysNothing(): void
    {
        $this->recordDrawWinnings()->handle(
            new RecordDrawWinningsCommand(
                $this->drawId,
                500.00,
                [['winningClass' => 2, 'amount' => 500.00]]
            )
        );

        $matches = $this->matchesByParticipant();

        // Nobody hit six numbers, so no row can claim the class 2 amount
        foreach ([7, 8, 9] as $participantId) {
            self::assertSame(0.0, Row::float($matches[$participantId], 'amount'));
        }
    }

    public function testTheTicketTotalIsWhatTheAdministratorEntered(): void
    {
        $this->recordDrawWinnings()->handle(
            new RecordDrawWinningsCommand(
                $this->drawId,
                500.00,
                [['winningClass' => 5, 'amount' => 300.00]]
            )
        );

        // The per-row split is an attribution; the year's total stays the
        // amount actually received.
        self::assertSame(500.00, $this->draws->totalWinnings($this->tippYearId));
    }

    public function testWinningClassesAreSummarisedForTheReadModel(): void
    {
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 100.00));

        $classes = $this->draws->winningClassesOf($this->drawId);

        self::assertCount(1, $classes, 'both winners are in the same class');
        self::assertSame(2, $classes[0]['rowCount']);
        self::assertSame(100.0, $classes[0]['amount']);

        $best = $this->draws->bestMatchOf($this->drawId);
        self::assertNotNull($best);
        self::assertSame(4, $best['matchedNumbers']);
        self::assertTrue($best['superzahlMatched']);
    }
}
