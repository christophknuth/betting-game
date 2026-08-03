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
use DateTimeImmutable;

/**
 * Which ticket a draw belongs to, asked in three places.
 *
 * Ticket periods overlap easily: `uk_year_period` only stops two of them from
 * starting on the same day, and a Laufzeit chosen in weeks means a four-week
 * Spielauftrag and a shorter one handed in after it cover the same Wednesday.
 * Three pieces of code then have to agree on which of them played:
 *
 * - `TicketRepository::findCovering()` - the write path, whose answer decides
 *   which rows are evaluated and which ticket the winnings are booked to
 * - `DrawRepository::findWithWinnings()` - the read path behind B-05/B-24,
 *   which decides whose rows are listed under the draw
 * - `DrawProjector` - the same evaluation again, on a rebuild
 *
 * They did not. The write path had no `ORDER BY` at all and got whatever the
 * storage engine returned first, while both readers took the newest period.
 * The result was a draw evaluated against one ticket with the rows of another
 * underneath it, every one of them "noch nicht ausgewertet" and staying that
 * way - because the row the evaluation wrote belonged to a ticket nobody was
 * looking at.
 */
final class CoveringTicketTest extends ApplicationTestCase
{
    private int $tippYearId;

    /** The ticket handed in first, covering the whole of January with one row. */
    private int $earlier;

    /** Handed in four days later with a second row on it - the one that counts. */
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
        $this->assignBetRow()->handle(new AssignBetRowCommand(1, $period->resourceId, [3, 12, 19, 27, 33, 45]));

        $this->startTippYear($this->tippYearId);

        // Four weeks from New Year's Day, with Anna's row alone on it
        $earlier = $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-01-01', 4, DrawSchedule::BOTH, 7, 'LOS-0001')
        );
        self::assertNotNull($earlier->resourceId);
        $this->earlier = $earlier->resourceId;

        // Ben joins, and the next Spielauftrag carries both rows
        $this->assignBetRow()->handle(new AssignBetRowCommand(2, $period->resourceId, [1, 2, 3, 4, 5, 6]));

        $later = $this->submitTicket()->handle(
            new SubmitTicketCommand($this->tippYearId, '2026-01-05', 3, DrawSchedule::BOTH, 3, 'LOS-0002')
        );
        self::assertNotNull($later->resourceId);
        $this->later = $later->resourceId;

        // A Wednesday both of them cover
        $drawn = $this->recordDraw()->handle(
            new RecordDrawCommand($this->tippYearId, '2026-01-07', [3, 12, 19, 27, 40, 41], 3)
        );
        self::assertNotNull($drawn->resourceId);
        $this->drawId = $drawn->resourceId;
    }

    public function testTheTicketHandedInLastIsTheOneThatPlays(): void
    {
        $covering = $this->tickets->findCovering($this->tippYearId, new DateTimeImmutable('2026-01-07'));

        self::assertNotNull($covering);
        self::assertSame(
            $this->later,
            $covering->id(),
            'the more recent Spielauftrag, not whichever row the engine returned first'
        );
    }

    public function testTheRowsUnderTheDrawAreTheRowsThatWereEvaluated(): void
    {
        $draw = $this->drawFromQuery();

        self::assertSame($this->later, $draw['ticket']['ticketId']);

        $rows = $draw['ticket']['rows'];
        self::assertCount(2, $rows, 'both rows of the later ticket');

        foreach ($rows as $row) {
            self::assertNotNull(
                $row['matchedNumbers'],
                "row {$row['ticketRowId']} is listed under the draw but was never evaluated"
            );
        }
    }

    public function testTheEarlierTicketIsNotEvaluatedForThisDraw(): void
    {
        // Not an oversight but the point: one draw, one Spielauftrag. Its rows
        // are evaluated for the draws only it covers - here the first six days
        // of January, which this test does not record a draw in.
        $matches = $this->db->fetchAll(
            '
            SELECT tr.ticket_id
            FROM ticket_row_match m
            JOIN ticket_row tr ON tr.ticket_row_id = m.ticket_row_id
            WHERE m.draw_id = ?
            ',
            [$this->drawId]
        );

        $ticketIds = array_map(static fn (array $row): int => Row::int($row, 'ticket_id'), $matches);

        self::assertSame([$this->later, $this->later], $ticketIds);
        self::assertNotContains($this->earlier, $ticketIds);
    }

    public function testARebuildPicksTheSameTicket(): void
    {
        $before = $this->rowMatches();

        $this->projections()->rebuildAll();

        self::assertSame(
            $before,
            $this->rowMatches(),
            'the projector reads the covering ticket its own way and has to reach the same one'
        );
    }

    public function testTheWinningsAreBookedToTheTicketWhoseRowsWereEvaluated(): void
    {
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($this->drawId, 50.00));

        $result = $this->db->fetchOne('SELECT ticket_id FROM ticket_draw_result WHERE draw_id = ?', [$this->drawId]);

        self::assertNotNull($result);
        self::assertSame($this->later, Row::int($result, 'ticket_id'));
    }

    /** @return array<string, mixed> */
    private function drawFromQuery(): array
    {
        $result = $this->getDraws()->handle(new GetDrawsQuery($this->tippYearId))->toArray();

        /** @var list<array<string, mixed>> $draws */
        $draws = $result['draws'];

        self::assertCount(1, $draws);

        return $draws[0];
    }

    /** @return list<array<string, mixed>> */
    private function rowMatches(): array
    {
        return $this->db->fetchAll(
            'SELECT ticket_row_id, matched_numbers, winning_class FROM ticket_row_match
             WHERE draw_id = ? ORDER BY ticket_row_id',
            [$this->drawId]
        );
    }
}
