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
use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Domain\ValueObject\DrawSchedule;
use BettingGame\Support\Row;

/**
 * OPS-04: that a rebuilt read model is the read model.
 *
 * The repositories write projections as they save, and the projectors write
 * the same rows from the event log. Two paths to the same tables will drift
 * unless something checks, so this plays a whole tipp year through the command
 * handlers, photographs every read model table, rebuilds from the event store
 * and compares. A mismatch anywhere fails here rather than in production after
 * a rebuild.
 */
final class ProjectionRebuildTest extends ApplicationTestCase
{
    /** @var list<string> */
    private const READ_MODELS = [
        'participant',
        'tipp_year',
        'membership',
        'bet_period',
        'bet_row',
        'ticket',
        'ticket_row',
        'draw',
        'ticket_draw_result',
        'ticket_row_match',
        'fee',
        'payout',
        'payout_share',
    ];

    /**
     * Everything below goes through the command handlers, so every row in the
     * read models has an event behind it. A fixture inserted with plain SQL
     * would have none, and the rebuild would rightly drop it.
     */
    private function playAWholeYear(): void
    {
        $this->db->execute(
            "
            INSERT INTO user (user_id, username, password_hash, email) VALUES
                (1, 'anna', 'x', 'anna@example.com'),
                (2, 'ben', 'x', 'ben@example.com')
            "
        );

        $this->participants->save(Participant::create(7, 1, new DisplayName('Anna'), true));
        $this->participants->save(Participant::create(8, 2, new DisplayName('Ben')));

        // Ben arrives pending, like a self-registration, and is approved - so
        // the rebuild has a participant.approved event to replay as well.
        $ben = $this->participants->findParticipant(8);
        self::assertNotNull($ben);
        $ben->changeStatus(true);
        $this->participants->save($ben);

        $year = $this->createTippYear()->handle(
            new CreateTippYearCommand('Tippjahr 2026', '2026-01-01', '2026-12-31', 1.20)
        );
        $tippYearId = $year->resourceId;
        self::assertNotNull($tippYearId);

        $q1 = $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($tippYearId, 'Q1 2026', '2026-01-01', '2026-03-31')
        );
        $q2 = $this->createBetPeriod()->handle(
            new CreateBetPeriodCommand($tippYearId, 'Q2 2026', '2026-04-01', '2026-06-30')
        );
        self::assertNotNull($q1->resourceId);
        self::assertNotNull($q2->resourceId);

        $this->addMember()->handle(new AddMemberCommand($tippYearId, 7));
        $this->addMember()->handle(new AddMemberCommand($tippYearId, 8));

        $this->assignBetRow()->handle(new AssignBetRowCommand(7, $q1->resourceId, [3, 12, 19, 27, 33, 45]));
        $this->assignBetRow()->handle(new AssignBetRowCommand(8, $q1->resourceId, [3, 12, 19, 27, 34, 46]));
        $this->assignBetRow()->handle(new AssignBetRowCommand(7, $q2->resourceId, [1, 2, 3, 4, 5, 6]));

        // A correction inside the running period, so bet_row.replaced is in the log
        $this->assignBetRow()->handle(
            new AssignBetRowCommand(8, $q1->resourceId, [7, 8, 9, 10, 11, 12], 'wrong slip transcribed')
        );

        $this->startTippYear($tippYearId);

        $january = $this->submitTicket()->handle(
            new SubmitTicketCommand($tippYearId, '2026-01-01', 4, DrawSchedule::BOTH, 7, 'LOT-2026-01')
        );
        self::assertNotNull($january->resourceId);

        // A shorter one on a single draw day, so the rebuild has both shapes
        $this->submitTicket()->handle(
            new SubmitTicketCommand($tippYearId, '2026-02-01', 2, DrawSchedule::SATURDAY, 3, 'LOT-2026-02')
        );

        $drawn = $this->recordDraw()->handle(
            new RecordDrawCommand($tippYearId, '2026-01-07', [3, 12, 19, 27, 40, 41], 7)
        );
        self::assertNotNull($drawn->resourceId);
        $this->recordDrawWinnings()->handle(new RecordDrawWinningsCommand($drawn->resourceId, 123.45));

        // A second draw with an explicit breakdown, and one with no winnings
        $second = $this->recordDraw()->handle(
            new RecordDrawCommand($tippYearId, '2026-01-10', [3, 12, 19, 33, 44, 45], 7)
        );
        self::assertNotNull($second->resourceId);
        $this->recordDrawWinnings()->handle(
            // Class by class: Anna's row hits five numbers plus the Superzahl
            new RecordDrawWinningsCommand($second->resourceId, null, [['winningClass' => 3, 'amountPerRow' => 300.00]])
        );

        $this->recordDraw()->handle(
            new RecordDrawCommand($tippYearId, '2026-02-04', [20, 21, 22, 23, 24, 25], 0)
        );

        $fees = $this->fees->findByTicket($january->resourceId);
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
        $this->recordFeePayment()->handle(
            new RecordFeePaymentCommand($fees[1]->id(), Fee::WAIVED, null, null, 'hardship', 'admin')
        );

        $this->closeTippYear($tippYearId);
        $this->distributePayout()->handle(new DistributePayoutCommand($tippYearId, true, null, 'admin'));
    }

    /**
     * Columns that record when something was computed rather than what was
     * computed. A rebuild recalculates now, so these legitimately move -
     * asserting on them would be asserting something false.
     *
     * Every other column, including all amounts and winning classes, is
     * compared exactly. This is the only exception, and it is one column.
     *
     * @var array<string, list<string>>
     */
    private const RECALCULATED_AT = [
        'ticket_row_match' => ['calculated_at'],
    ];

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach (self::READ_MODELS as $table) {
            $rows = $this->db->fetchAll("SELECT * FROM $table ORDER BY 1");
            $volatile = self::RECALCULATED_AT[$table] ?? [];

            if ($volatile !== []) {
                $rows = array_map(
                    static function (array $row) use ($volatile): array {
                        foreach ($volatile as $column) {
                            unset($row[$column]);
                        }

                        return $row;
                    },
                    $rows
                );
            }

            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }

    public function testTheScenarioActuallyFillsEveryReadModel(): void
    {
        $this->playAWholeYear();

        // Guards the test itself: comparing two empty tables proves nothing
        foreach ($this->snapshot() as $table => $rows) {
            self::assertNotEmpty($rows, "$table stayed empty, the comparison below would be vacuous");
        }
    }

    public function testRebuildingReproducesEveryReadModelExactly(): void
    {
        $this->playAWholeYear();
        $before = $this->snapshot();

        $rebuilt = $this->projections()->rebuildAll();

        self::assertCount(7, $rebuilt);

        $after = $this->snapshot();

        foreach (self::READ_MODELS as $table) {
            self::assertSame(
                $before[$table],
                $after[$table],
                "$table differs after a rebuild - the write path and the projector disagree"
            );
        }
    }

    public function testARebuildSurvivesTicketsWrittenBeforeTheLaufzeitExisted(): void
    {
        // Tickets used to be handed in with a period and a number of draws
        // typed out, so their events carry no Laufzeit. The log is immutable:
        // a projector that insisted on one would fail on every ticket ever
        // submitted, and a rebuild is the worst moment to find that out.
        $this->playAWholeYear();

        $stripped = $this->db->fetchAll(
            "SELECT event_store_id, event_data FROM event_store WHERE event_type = 'ticket.submitted'"
        );
        self::assertNotEmpty($stripped);

        foreach ($stripped as $event) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode(Row::string($event, 'event_data'), true);
            unset($payload['duration_weeks'], $payload['draw_days']);

            $this->db->execute(
                'UPDATE event_store SET event_data = ? WHERE event_store_id = ?',
                [json_encode($payload), Row::int($event, 'event_store_id')]
            );
        }

        $this->projections()->rebuildAll();

        $ticket = $this->db->fetchOne("SELECT * FROM ticket WHERE period_start = '2026-01-01'");

        self::assertNotNull($ticket);
        self::assertNull($ticket['duration_weeks'], 'nothing is invented, and nothing crashes');
        self::assertNull($ticket['draw_days']);
        self::assertSame(8, Row::int($ticket, 'draw_count'), 'what it played is in the event either way');
        self::assertSame('2026-01-28', Row::string($ticket, 'period_end'));
    }

    public function testRebuildingIsIdempotent(): void
    {
        $this->playAWholeYear();

        $this->projections()->rebuildAll();
        $once = $this->snapshot();

        $this->projections()->rebuildAll();
        $twice = $this->snapshot();

        self::assertSame($once, $twice);
    }

    public function testRebuildingOneProjectionAlsoRebuildsWhatCascadesFromIt(): void
    {
        $this->playAWholeYear();
        $before = $this->snapshot();

        // Emptying tipp_year cascades into bet_period, ticket and draw, so
        // those have to come back too.
        $rebuilt = $this->projections()->rebuild('tipp_year_read_model');

        self::assertSame(
            ['tipp_year_read_model', 'bet_period_read_model', 'bet_row_read_model',
                'ticket_read_model', 'draw_read_model', 'fee_read_model'],
            array_map(static fn ($status): string => $status->name, $rebuilt)
        );

        self::assertSame($before, $this->snapshot());
    }

    public function testALeafProjectionRebuildsOnItsOwn(): void
    {
        $this->playAWholeYear();
        $before = $this->snapshot();

        $rebuilt = $this->projections()->rebuild('fee_read_model');

        self::assertCount(1, $rebuilt, 'nothing hangs off the fee read model');
        self::assertSame($before, $this->snapshot());
    }

    public function testStatusesReportNoLagAfterARebuild(): void
    {
        $this->playAWholeYear();
        $this->projections()->rebuildAll();

        $statuses = $this->projections()->statuses();

        self::assertCount(7, $statuses);

        foreach ($statuses as $status) {
            self::assertSame('running', $status->status, $status->name);
            self::assertSame(0, $status->lag, $status->name);
            self::assertTrue($status->toArray()['upToDate']);
        }
    }

    public function testAnUnknownProjectionIsRejected(): void
    {
        $this->expectExceptionMessageMatches('/no projection called/');
        $this->projections()->rebuild('nope_read_model');
    }
}
