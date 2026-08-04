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
use BettingGame\Application\Query\GetDrawsQuery;
use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Domain\ValueObject\DrawSchedule;
use BettingGame\Support\Row;

/**
 * Recording the winnings of a draw a second time corrects them. It used to
 * duplicate the draw.
 *
 * `ticket_draw_result` was unique on (ticket_id, draw_id), and the covering
 * ticket is worked out afresh on every recording. Against the same ticket the
 * upsert did correct the figure - but where two Spielaufträge overlap, a slip
 * handed in between the two recordings takes the draw over, and the correction
 * landed on a key nothing occupied. The result was two results for one draw:
 * the list showed it twice, and the year's total counted both amounts.
 *
 * A draw is played by exactly one ticket, so it has exactly one result. The
 * fixture is the case that bites: 19,80 € booked to the first slip, then 14,50 €
 * to the one that has meanwhile taken the Wednesday over.
 */
final class CorrectWinningsTest extends ApplicationTestCase
{
    private int $tippYearId;

    /** Four weeks from New Year's Day, Anna's row alone on it. */
    private int $earlier;

    /** Handed in after the winnings were first recorded, and it covers the same Wednesday. */
    private int $later;

    private int $drawId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->participants->save(Participant::create(1, null, new DisplayName('Anna'), true));
        $this->participants->save(Participant::create(2, null, new DisplayName('Ben'), true));

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
        $this->addMember()->handle(new AddMemberCommand($this->tippYearId, 2));

        // Anna matches four of the six; Ben, further down, matches none
        $this->assignBetRow()->handle(new AssignBetRowCommand(1, $period->resourceId, [3, 12, 19, 27, 33, 45]));

        $this->startTippYear($this->tippYearId);

        $earlier = $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-01-01', 4, DrawSchedule::BOTH, 7, 'LOS-0001')
        );
        self::assertNotNull($earlier->resourceId);
        $this->earlier = $earlier->resourceId;

        $drawn = $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2026-01-07', [3, 12, 19, 27, 40, 41], 3)
        );
        self::assertNotNull($drawn->resourceId);
        $this->drawId = $drawn->resourceId;

        // Read off the statement, booked to the only slip there is
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 19.80));

        // Only now does the second Spielauftrag arrive - Ben has joined, and it
        // covers the Wednesday that has already been evaluated.
        $this->assignBetRow()->handle(new AssignBetRowCommand(2, $period->resourceId, [1, 2, 4, 5, 6, 8]));

        $later = $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-01-05', 3, DrawSchedule::BOTH, 3, 'LOS-0002')
        );
        self::assertNotNull($later->resourceId);
        $this->later = $later->resourceId;
    }

    public function testTheFirstRecordingBooksToTheOnlySlipThereIs(): void
    {
        // The starting point the correction below is a correction of
        self::assertSame([[$this->earlier, 19.80]], $this->results());
    }

    public function testRecordingTheWinningsAgainReplacesTheResultInsteadOfAddingOne(): void
    {
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 14.50));

        self::assertSame(
            [[$this->later, 14.50]],
            $this->results(),
            'one draw, one result - and on the slip that plays it now'
        );
    }

    public function testTheYearsTotalCountsTheCorrectionOnceRatherThanBothFigures(): void
    {
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 14.50));

        // 19.80 + 14.50 = 34.30 was what the year showed, and it is the reason
        // this was noticed at all: money that was never won.
        self::assertSame(14.50, $this->draws->totalWinnings($this->tippYearId));
    }

    public function testTheDrawIsListedOnceAfterACorrection(): void
    {
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 14.50));

        $result = $this->getDraws()->handle(new GetDrawsQuery($this->tippYearId))->toArray();

        /** @var list<array<string, mixed>> $draws */
        $draws = $result['draws'];

        // The read path joins the results onto the draw, so a second result did
        // not merely add an amount - it doubled the draw itself in the list.
        self::assertCount(1, $draws);

        /** @var array<string, mixed> $ticket */
        $ticket = $draws[0]['ticket'];
        self::assertSame($this->later, $ticket['ticketId']);
    }

    public function testTheRowsOfTheSlipLeftBehindAreDropped(): void
    {
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 14.50));

        // Anna's row sits on both slips, but as two separate snapshots. The one
        // belonging to the slip that no longer plays has to go, or it stays as
        // the result of a draw it did not take part in - and `bestMatchOf` and
        // the class summary read the matches by draw, without asking whose.
        self::assertSame([$this->later, $this->later], $this->evaluatedTicketIds());
    }

    public function testARebuildReproducesTheCorrection(): void
    {
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 14.50));

        $this->projections()->rebuildAll();

        // Both winnings events are replayed, in the order they happened. The
        // projector has to fold them into the same single result the write path
        // holds - a plain INSERT made two of them again here.
        self::assertSame([[$this->later, 14.50]], $this->results());
        self::assertSame([$this->later, $this->later], $this->evaluatedTicketIds());
    }

    /** @return list<array{0: int, 1: float}> ticket and amount per stored result */
    private function results(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ticket_id, total_amount FROM ticket_draw_result
             WHERE draw_id = ? ORDER BY ticket_draw_result_id',
            [$this->drawId]
        );

        return array_map(
            static fn (array $row): array => [Row::int($row, 'ticket_id'), Row::float($row, 'total_amount')],
            $rows
        );
    }

    /** @return list<int> the ticket behind every row evaluated for this draw */
    private function evaluatedTicketIds(): array
    {
        $rows = $this->db->fetchAll(
            '
            SELECT tr.ticket_id
            FROM ticket_row_match m
            JOIN ticket_row tr ON tr.ticket_row_id = m.ticket_row_id
            WHERE m.draw_id = ?
            ORDER BY m.ticket_row_match_id
            ',
            [$this->drawId]
        );

        return array_map(static fn (array $row): int => Row::int($row, 'ticket_id'), $rows);
    }
}
