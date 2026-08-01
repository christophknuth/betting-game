<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Domain\Model\BetRow;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\Exception\DuplicateEntryException;
use BettingGame\Infrastructure\Persistence\BetRowRepository;
use DateTimeImmutable;
use BettingGame\Infrastructure\Persistence\ProjectionStateRepository;

final class BetRowRepositoryTest extends IntegrationTestCase
{
    private BetRowRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new BetRowRepository(
            $this->db,
            $this->eventStore,
            new ProjectionStateRepository($this->db),
        );

        $this->db->execute(
            "
            INSERT INTO tipp_year (tipp_year_id, name, start_date, end_date, status, ticket_cost_per_row, version)
            VALUES (1, 'Tippjahr 2026', '2026-01-01', '2026-12-31', 'running', 1.20, 0)
            "
        );

        $this->db->execute(
            "
            INSERT INTO bet_period (bet_period_id, tipp_year_id, name, start_date, end_date, sequence, version)
            VALUES
                (1, 1, 'Q1 2026', '2026-01-01', '2026-03-31', 1, 0),
                (2, 1, 'Q2 2026', '2026-04-01', '2026-06-30', 2, 0)
            "
        );
    }

    private function numbers(int ...$values): LottoNumbers
    {
        return new LottoNumbers($values === [] ? [3, 12, 19, 27, 33, 45] : array_values($values));
    }

    private function givenRow(int $id, int $participantId, int $periodId, ?LottoNumbers $numbers = null): BetRow
    {
        $row = BetRow::assign($id, $participantId, $periodId, $numbers ?? $this->numbers());
        $this->repository->save($row);

        return $row;
    }

    public function testNumbersRoundTripSortedThroughJson(): void
    {
        $this->givenParticipant(7);
        $this->givenRow(1, 7, 1, $this->numbers(45, 3, 33, 12, 27, 19));

        $loaded = $this->repository->find(1);

        self::assertNotNull($loaded);
        self::assertSame([3, 12, 19, 27, 33, 45], $loaded->numbers()->toArray());
    }

    public function testOneRowPerParticipantAndPeriodIsEnforcedByTheSchema(): void
    {
        $this->givenParticipant(7);
        $this->givenRow(1, 7, 1);

        // A different period is fine
        $this->givenRow(2, 7, 2, $this->numbers(1, 2, 3, 4, 5, 6));
        self::assertNotNull($this->repository->find(2));

        // A second row in the same period is not
        $this->expectException(DuplicateEntryException::class);
        $this->expectExceptionMessageMatches('/uk_participant_period/');
        $this->givenRow(3, 7, 1, $this->numbers(7, 8, 9, 10, 11, 12));
    }

    public function testARejectedDuplicateLeavesNoEventBehind(): void
    {
        $this->givenParticipant(7);
        $this->givenRow(1, 7, 1);

        try {
            $this->givenRow(2, 7, 1, $this->numbers(7, 8, 9, 10, 11, 12));
            self::fail('the unique key should have rejected the second row');
        } catch (DuplicateEntryException) {
            // expected
        }

        // The append and the insert share a transaction, so the rejected row
        // must not leave a bet_row.assigned event describing a row that is not there.
        self::assertSame(0, $this->eventStore->getStreamVersion('bet_row-2'));
        self::assertCount(0, $this->eventStore->getStream('bet_row-2'));

        $events = $this->db->fetchAll("SELECT * FROM event_store WHERE aggregate_type = 'bet_row'");
        self::assertCount(1, $events, 'only the first row was ever written');
    }

    public function testReplacingUpdatesTheProjectionAndAppendsAnEvent(): void
    {
        $this->givenParticipant(7);
        $this->givenRow(1, 7, 1);

        $loaded = $this->repository->find(1);
        self::assertNotNull($loaded);
        $loaded->replace($this->numbers(1, 2, 3, 4, 5, 6), 'wrong slip transcribed');
        $this->repository->save($loaded);

        $reloaded = $this->repository->find(1);
        self::assertNotNull($reloaded);
        self::assertSame([1, 2, 3, 4, 5, 6], $reloaded->numbers()->toArray());
        self::assertSame(2, $this->eventStore->getStreamVersion('bet_row-1'));

        $events = $this->eventStore->getStream('bet_row-1');
        self::assertCount(2, $events);
        self::assertSame('bet_row.assigned', $events[0]->eventType());
        self::assertSame('bet_row.replaced', $events[1]->eventType());
    }

    public function testFindActiveRowOfPicksTheRowOfThePeriodCoveringTheDay(): void
    {
        $this->givenParticipant(7);
        $this->givenRow(1, 7, 1, $this->numbers(3, 12, 19, 27, 33, 45));
        $this->givenRow(2, 7, 2, $this->numbers(1, 2, 3, 4, 5, 6));

        $q1 = $this->repository->findActiveRowOf(7, 1, new DateTimeImmutable('2026-02-15'));
        $q2 = $this->repository->findActiveRowOf(7, 1, new DateTimeImmutable('2026-05-15'));

        self::assertNotNull($q1);
        self::assertNotNull($q2);
        self::assertSame([3, 12, 19, 27, 33, 45], $q1->numbers()->toArray());
        self::assertSame([1, 2, 3, 4, 5, 6], $q2->numbers()->toArray());
    }

    public function testFindActiveRowOfReturnsNothingOutsideEveryPeriod(): void
    {
        $this->givenParticipant(7);
        $this->givenRow(1, 7, 1);

        self::assertNull($this->repository->findActiveRowOf(7, 1, new DateTimeImmutable('2026-11-01')));
    }

    public function testRowsForATicketOnlyIncludeActiveMembers(): void
    {
        $this->givenParticipant(7, 'Anna');
        $this->givenParticipant(8, 'Ben');
        $this->givenParticipant(9, 'Cara');

        $this->givenRow(1, 7, 1);
        $this->givenRow(2, 8, 1, $this->numbers(1, 2, 3, 4, 5, 6));
        $this->givenRow(3, 9, 1, $this->numbers(7, 8, 9, 10, 11, 12));

        $this->db->execute(
            "
            INSERT INTO membership (participant_id, tipp_year_id, status) VALUES
                (7, 1, 'active'),
                (8, 1, 'ended')
            "
        );

        $rows = $this->repository->findRowsForTicket(1, new DateTimeImmutable('2026-01-01'));

        // Ben has left, Cara never joined - only Anna's row goes on the ticket
        self::assertCount(1, $rows);
        self::assertSame(7, $rows[0]->participantId());
    }

    public function testRowsForATicketComeFromThePeriodCoveringItsFirstDay(): void
    {
        $this->givenParticipant(7);
        $this->givenRow(1, 7, 1);
        $this->givenRow(2, 7, 2, $this->numbers(1, 2, 3, 4, 5, 6));

        $this->db->execute(
            "INSERT INTO membership (participant_id, tipp_year_id, status) VALUES (7, 1, 'active')"
        );

        $april = $this->repository->findRowsForTicket(1, new DateTimeImmutable('2026-04-01'));

        self::assertCount(1, $april);
        self::assertSame([1, 2, 3, 4, 5, 6], $april[0]->numbers()->toArray());
    }

    public function testFindByParticipantAndPeriod(): void
    {
        $this->givenParticipant(7);
        $this->givenRow(1, 7, 1);

        self::assertNotNull($this->repository->findByParticipantAndPeriod(7, 1));
        self::assertNull($this->repository->findByParticipantAndPeriod(7, 2));
    }
}
