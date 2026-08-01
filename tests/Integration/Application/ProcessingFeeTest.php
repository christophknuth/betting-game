<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Application;

use BettingGame\Application\Command\AddMemberCommand;
use BettingGame\Application\Command\AssignBetRowCommand;
use BettingGame\Application\Command\CreateBetPeriodCommand;
use BettingGame\Application\Command\CreateTippYearCommand;
use BettingGame\Application\Command\SubmitTicketCommand;
use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Support\Row;

/**
 * The Bearbeitungsentgelt through the whole write path (B-10, B-12).
 *
 * The cost of a ticket is not just rows x draws x price: the lottery company
 * charges a fee per Spielauftrag, at a rate that depends on how long the order
 * runs. The rates are agreed for the season and live on the tipp year; the
 * ticket records which one it was charged, so a later change to the price list
 * does not rewrite what a submitted ticket cost.
 */
final class ProcessingFeeTest extends ApplicationTestCase
{
    public function testAMonthlyTicketIsChargedTheMultiWeekRate(): void
    {
        $tippYearId = $this->givenAYearWithThreeMembers();

        $this->submitTicket()->handle(new SubmitTicketCommand(
            $tippYearId,
            '2026-01-01',
            '2026-01-31',
            9,
            null,
            'LOT-2026-01'
        ));

        $ticket = $this->db->fetchOne('SELECT * FROM ticket WHERE tipp_year_id = ?', [$tippYearId]);
        self::assertNotNull($ticket);

        // 3 rows x 9 draws x 1.20 = 32.40, plus the multi-week fee of 1.00
        self::assertSame(1.00, Row::float($ticket, 'processing_fee'));
        self::assertSame(33.40, Row::float($ticket, 'total_cost'));
    }

    public function testAWeekLongTicketIsChargedTheCheaperRate(): void
    {
        $tippYearId = $this->givenAYearWithThreeMembers();

        $this->submitTicket()->handle(new SubmitTicketCommand(
            $tippYearId,
            '2026-01-05',
            '2026-01-11',
            2,
            null,
            'LOT-2026-W2'
        ));

        $ticket = $this->db->fetchOne('SELECT * FROM ticket WHERE tipp_year_id = ?', [$tippYearId]);
        self::assertNotNull($ticket);

        // 3 rows x 2 draws x 1.20 = 7.20, plus the single-week fee of 0.60
        self::assertSame(0.60, Row::float($ticket, 'processing_fee'));
        self::assertSame(7.80, Row::float($ticket, 'total_cost'));
    }

    public function testTheFeesChargedAddUpToTheTicketExactly(): void
    {
        // The reason the split had to move to EvenSplit: with the processing
        // fee the total stops being a multiple of the row count, and rounding
        // each share separately would under-bill the syndicate by a cent on
        // every ticket.
        $tippYearId = $this->givenAYearWithThreeMembers();

        $this->submitTicket()->handle(new SubmitTicketCommand(
            $tippYearId,
            '2026-01-01',
            '2026-01-31',
            9,
            null,
            null
        ));

        $charged = $this->db->fetchAll(
            'SELECT f.amount FROM fee f JOIN ticket t ON t.ticket_id = f.ticket_id
             WHERE t.tipp_year_id = ?',
            [$tippYearId]
        );

        self::assertCount(3, $charged);

        $sum = array_sum(array_map(static fn (array $row): float => Row::float($row, 'amount'), $charged));

        self::assertSame(33.40, round($sum, 2), 'the fees have to add back up to the ticket');
        self::assertSame([11.14, 11.13, 11.13], array_map(
            static fn (array $row): float => Row::float($row, 'amount'),
            $charged
        ));
    }

    public function testARebuildSurvivesEventsWrittenBeforeTheFeeExisted(): void
    {
        // The event log is immutable, so events written before the price list
        // existed carry none of these fields. A projector that demanded them
        // would fail on every one of them - and a rebuild is exactly when that
        // would be discovered, at the worst possible moment.
        $tippYearId = $this->givenAYearWithThreeMembers();

        $this->submitTicket()->handle(new SubmitTicketCommand(
            $tippYearId,
            '2026-01-01',
            '2026-01-31',
            9,
            null,
            null
        ));

        $this->stripFeeFieldsFromTheEventLog();

        $this->projections()->rebuildAll();

        $ticket = $this->db->fetchOne('SELECT * FROM ticket WHERE tipp_year_id = ?', [$tippYearId]);
        $year = $this->db->fetchOne('SELECT * FROM tipp_year WHERE tipp_year_id = ?', [$tippYearId]);

        self::assertNotNull($ticket);
        self::assertNotNull($year);
        self::assertSame(0.0, Row::float($ticket, 'processing_fee'), 'no fee is recorded, not a crash');
        self::assertSame(0.0, Row::float($year, 'processing_fee_single_week'));
    }

    /**
     * Rewrites the stored payloads to what they looked like before the fee
     * existed, which no amount of fixture-building can imitate as honestly.
     */
    private function stripFeeFieldsFromTheEventLog(): void
    {
        $events = $this->db->fetchAll(
            "SELECT event_store_id, event_data FROM event_store
             WHERE event_type IN ('tipp_year.created', 'ticket.submitted')"
        );

        foreach ($events as $event) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode(Row::string($event, 'event_data'), true);

            unset(
                $payload['processing_fee'],
                $payload['processing_fee_single_week'],
                $payload['processing_fee_multi_week']
            );

            $this->db->execute(
                'UPDATE event_store SET event_data = ? WHERE event_store_id = ?',
                [json_encode($payload), Row::int($event, 'event_store_id')]
            );
        }
    }

    private function givenAYearWithThreeMembers(): int
    {
        $this->db->execute(
            "INSERT INTO user (user_id, username, password_hash, email) VALUES
                (1, 'anna', 'x', 'a@example.com'),
                (2, 'ben', 'x', 'b@example.com'),
                (3, 'cara', 'x', 'c@example.com')"
        );

        foreach ([[7, 1, 'Anna'], [8, 2, 'Ben'], [9, 3, 'Cara']] as [$id, $userId, $name]) {
            $this->participants->save(Participant::create($id, $userId, new DisplayName($name), true));
        }

        $year = $this->createTippYear()->handle(new CreateTippYearCommand(
            'Tippjahr 2026',
            '2026-01-01',
            '2026-12-31',
            1.20,
            0.60,
            1.00
        ));
        self::assertNotNull($year->resourceId);

        $period = $this->createBetPeriod()->handle(new CreateBetPeriodCommand(
            $year->resourceId,
            'Gesamt 2026',
            '2026-01-01',
            '2026-12-31'
        ));
        self::assertNotNull($period->resourceId);

        foreach ([7, 8, 9] as $participantId) {
            $this->addMember()->handle(new AddMemberCommand($year->resourceId, $participantId));
            $this->assignBetRow()->handle(new AssignBetRowCommand(
                $participantId,
                $period->resourceId,
                [3, 12, 19, 27, 33, 45]
            ));
        }

        // B-12 takes tickets only from a running year.
        $this->startTippYear($year->resourceId);

        return $year->resourceId;
    }
}
