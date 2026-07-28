<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Domain\Model\Fee;
use BettingGame\Domain\Model\Ticket;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use BettingGame\Infrastructure\Persistence\FeeRepository;
use BettingGame\Support\Row;
use BettingGame\Infrastructure\Persistence\TicketRepository;
use DateTimeImmutable;

/**
 * Ticket and fee together, because a fee only exists for a ticket - B-12
 * creates both in one go and B-03 reads them joined.
 */
final class TicketAndFeeRepositoryTest extends IntegrationTestCase
{
    private TicketRepository $tickets;
    private FeeRepository $fees;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tickets = new TicketRepository($this->db, $this->eventStore);
        $this->fees = new FeeRepository($this->db, $this->eventStore);

        $this->givenParticipant(7, 'Anna');
        $this->givenParticipant(8, 'Ben');

        $this->db->execute(
            "
            INSERT INTO tipp_year (tipp_year_id, name, start_date, end_date, status, ticket_cost_per_row, version)
            VALUES (1, 'Tippjahr 2026', '2026-01-01', '2026-12-31', 'running', 1.20, 0)
            "
        );

        $this->db->execute(
            "
            INSERT INTO bet_period (bet_period_id, tipp_year_id, name, start_date, end_date, sequence, version)
            VALUES (1, 1, '2026 gesamt', '2026-01-01', '2026-12-31', 1, 0)
            "
        );

        $this->db->execute(
            "
            INSERT INTO bet_row (bet_row_id, participant_id, bet_period_id, numbers, version) VALUES
                (1, 7, 1, '[3,12,19,27,33,45]', 0),
                (2, 8, 1, '[1,2,3,4,5,6]', 0)
            "
        );
    }

    private function givenTicket(int $id = 1, string $start = '2026-01-01', string $end = '2026-01-31'): Ticket
    {
        $ticket = Ticket::submit(
            $id,
            1,
            new DateTimeImmutable($start),
            new DateTimeImmutable($end),
            9,
            1.20,
            [
                ['betRowId' => 1, 'participantId' => 7, 'numbers' => new LottoNumbers([3, 12, 19, 27, 33, 45])],
                ['betRowId' => 2, 'participantId' => 8, 'numbers' => new LottoNumbers([1, 2, 3, 4, 5, 6])],
            ],
            new Superzahl(7),
            'LOT-2026-01'
        );

        $this->tickets->save($ticket);

        return $ticket;
    }

    public function testTicketRoundTripsWithItsRowSnapshots(): void
    {
        $this->givenTicket();

        $loaded = $this->tickets->find(1);

        self::assertNotNull($loaded);
        self::assertSame(2, $loaded->rowCount());
        self::assertSame(9, $loaded->drawCount());
        self::assertSame(21.60, $loaded->totalCost(), '2 rows x 9 draws x 1.20');
        self::assertSame(10.80, $loaded->feePerParticipant());
        self::assertSame(7, $loaded->superzahl()?->value());
        self::assertSame('LOT-2026-01', $loaded->lotteryReference());
        self::assertSame([7, 8], $loaded->participantIds());
    }

    public function testCorrectingABetRowLeavesASubmittedTicketAlone(): void
    {
        $this->givenTicket();

        // The standing row changes after the ticket went in
        $this->db->execute("UPDATE bet_row SET numbers = '[10,20,30,40,41,42]' WHERE bet_row_id = 1");

        $loaded = $this->tickets->find(1);

        self::assertNotNull($loaded);
        self::assertSame(
            [3, 12, 19, 27, 33, 45],
            $loaded->rows()[0]['numbers']->toArray(),
            'the ticket carries a snapshot, not a reference'
        );
    }

    public function testFindCoveringResolvesTheTicketADrawFallsInto(): void
    {
        $this->givenTicket(1, '2026-01-01', '2026-01-31');
        $this->givenTicket(2, '2026-02-01', '2026-02-28');

        $january = $this->tickets->findCovering(1, new DateTimeImmutable('2026-01-14'));
        $february = $this->tickets->findCovering(1, new DateTimeImmutable('2026-02-14'));

        self::assertSame(1, $january?->id());
        self::assertSame(2, $february?->id());
        self::assertNull($this->tickets->findCovering(1, new DateTimeImmutable('2026-03-14')));
    }

    public function testParticipationMarksTicketsTheOwnRowWasNotOn(): void
    {
        $this->givenTicket(1, '2026-01-01', '2026-01-31');

        // A second ticket carrying only Anna's row
        $ticket = Ticket::submit(
            2,
            1,
            new DateTimeImmutable('2026-02-01'),
            new DateTimeImmutable('2026-02-28'),
            8,
            1.20,
            [['betRowId' => 1, 'participantId' => 7, 'numbers' => new LottoNumbers([3, 12, 19, 27, 33, 45])]]
        );
        $this->tickets->save($ticket);

        $ben = $this->tickets->findWithParticipation(1, 8);

        self::assertCount(2, $ben, 'both tickets show up, joining mid-year is normal');

        $byId = [];
        foreach ($ben as $row) {
            $byId[Row::int($row, 'ticket_id')] = Row::bool($row, 'participated');
        }

        self::assertTrue($byId[1]);
        self::assertFalse($byId[2], 'Ben was not on the February ticket');
    }

    public function testFeeIsChargedOpenAndBecomesPaid(): void
    {
        $ticket = $this->givenTicket();

        $fee = Fee::charge(1, 7, $ticket->id(), $ticket->feePerParticipant(), new DateTimeImmutable('2026-01-31'));
        $this->fees->save($fee);

        $loaded = $this->fees->find(1);
        self::assertNotNull($loaded);
        self::assertTrue($loaded->isOpen());
        self::assertSame(10.80, $loaded->amount());

        $loaded->markPaid('bank transfer', 'admin', new DateTimeImmutable('2026-01-20 10:00:00'));
        $this->fees->save($loaded);

        $reloaded = $this->fees->find(1);
        self::assertNotNull($reloaded);
        self::assertSame(Fee::PAID, $reloaded->status());
        self::assertSame('bank transfer', $reloaded->paymentMethod());
        self::assertSame('admin', $reloaded->bookedBy());
        self::assertSame('2026-01-20 10:00:00', $reloaded->paidAt()?->format('Y-m-d H:i:s'));
        self::assertSame(2, $this->eventStore->getStreamVersion('fee-1'));
    }

    public function testAParticipantOwesOneFeePerTicket(): void
    {
        $ticket = $this->givenTicket();

        $this->fees->save(Fee::charge(1, 7, $ticket->id(), 10.80, new DateTimeImmutable('2026-01-31')));

        $this->expectExceptionMessageMatches('/uk_participant_ticket/');
        $this->fees->save(Fee::charge(2, 7, $ticket->id(), 10.80, new DateTimeImmutable('2026-01-31')));
    }

    public function testFeesOfAParticipantCarryTheTicketPeriod(): void
    {
        $this->givenTicket(1, '2026-01-01', '2026-01-31');
        $this->givenTicket(2, '2026-02-01', '2026-02-28');

        $this->fees->save(Fee::charge(1, 7, 1, 10.80, new DateTimeImmutable('2026-01-31')));
        $this->fees->save(Fee::charge(2, 7, 2, 10.80, new DateTimeImmutable('2026-02-28')));

        $rows = $this->fees->findByParticipant(7);

        self::assertCount(2, $rows);
        self::assertSame('2026-02-01', Row::string($rows[0], 'period_start'), 'newest first');
        self::assertSame(21.60, $this->fees->openTotalOf(7));
    }

    public function testPaidFeesDropOutOfTheOpenTotal(): void
    {
        $this->givenTicket();
        $fee = Fee::charge(1, 7, 1, 10.80, new DateTimeImmutable('2026-01-31'));
        $this->fees->save($fee);

        self::assertSame(10.80, $this->fees->openTotalOf(7));

        $fee->markPaid('cash', 'admin');
        $this->fees->save($fee);

        self::assertSame(0.0, $this->fees->openTotalOf(7));
    }

    public function testFindByTicketReturnsEveryParticipantsFee(): void
    {
        $this->givenTicket();

        $this->fees->save(Fee::charge(1, 7, 1, 10.80, new DateTimeImmutable('2026-01-31')));
        $this->fees->save(Fee::charge(2, 8, 1, 10.80, new DateTimeImmutable('2026-01-31')));

        self::assertCount(2, $this->fees->findByTicket(1));
    }
}
