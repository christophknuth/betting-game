<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Domain\Exception\DuplicateEntryException;
use BettingGame\Domain\Model\Draw;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use BettingGame\Infrastructure\Persistence\DrawRepository;
use BettingGame\Support\Row;
use DateTimeImmutable;

final class DrawRepositoryTest extends IntegrationTestCase
{
    private DrawRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DrawRepository($this->db, $this->eventStore);

        $this->givenParticipant(7, 'Anna');

        $this->db->execute(
            "
            INSERT INTO tipp_year (tipp_year_id, name, start_date, end_date, status, ticket_cost_per_row, version)
            VALUES (1, 'Tippjahr 2026', '2026-01-01', '2026-12-31', 'running', 1.20, 0)
            "
        );

        $this->db->execute(
            "
            INSERT INTO ticket (
                ticket_id, tipp_year_id, period_start, period_end,
                superzahl, row_count, draw_count, total_cost, status, version
            ) VALUES (1, 1, '2026-01-01', '2026-01-31', 7, 1, 9, 10.80, 'submitted', 0)
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
            INSERT INTO bet_row (bet_row_id, participant_id, bet_period_id, numbers, version)
            VALUES (1, 7, 1, '[3,12,19,27,33,45]', 0)
            "
        );

        $this->db->execute(
            "
            INSERT INTO ticket_row (ticket_row_id, ticket_id, bet_row_id, numbers)
            VALUES (1, 1, 1, '[3,12,19,27,33,45]')
            "
        );
    }

    private function givenDraw(int $id, string $date, int $superzahl = 7): Draw
    {
        $draw = Draw::record(
            $id,
            1,
            new DateTimeImmutable($date),
            new LottoNumbers([3, 12, 19, 27, 40, 41]),
            new Superzahl($superzahl)
        );

        $this->repository->save($draw);

        return $draw;
    }

    public function testDrawRoundTrips(): void
    {
        $this->givenDraw(1, '2026-01-07');

        $loaded = $this->repository->find(1);

        self::assertNotNull($loaded);
        self::assertSame([3, 12, 19, 27, 40, 41], $loaded->numbers()?->toArray());
        self::assertSame(7, $loaded->superzahl()?->value());
        self::assertSame(Draw::DRAWN, $loaded->status());
        self::assertSame('2026-01-07', $loaded->drawDate()->format('Y-m-d'));
    }

    public function testADrawDateExistsOnlyOnce(): void
    {
        $this->givenDraw(1, '2026-01-07');

        // The repository translates the rejected unique key into a domain
        // exception, so the application layer never sees a PDOException.
        $this->expectException(DuplicateEntryException::class);
        $this->expectExceptionMessageMatches('/uk_draw_date/');
        $this->givenDraw(2, '2026-01-07');
    }

    public function testAScheduledDrawHasNoNumbersYet(): void
    {
        $this->db->execute(
            "
            INSERT INTO draw (draw_id, tipp_year_id, draw_date, status, version)
            VALUES (1, 1, '2026-01-07', 'scheduled', 0)
            "
        );

        $loaded = $this->repository->find(1);

        self::assertNotNull($loaded);
        self::assertNull($loaded->numbers());
        self::assertNull($loaded->superzahl());
        self::assertNull($loaded->recordedAt());
    }

    public function testRecordingWinningsProjectsTheTicketResult(): void
    {
        $draw = $this->givenDraw(1, '2026-01-07');

        $draw->recordWinnings(1, 123.45, [['winning_class' => 5, 'amount' => 123.45]]);
        $this->repository->save($draw);

        $rows = $this->repository->findWithWinnings(1);

        self::assertCount(1, $rows);
        self::assertSame(123.45, Row::float($rows[0], 'total_amount'));
        self::assertSame(1, Row::int($rows[0], 'ticket_id'));

        $reloaded = $this->repository->find(1);
        self::assertNotNull($reloaded);
        self::assertSame(Draw::EVALUATED, $reloaded->status());
    }

    public function testDrawsWithoutWinningsStillShowUp(): void
    {
        $this->givenDraw(1, '2026-01-07');

        $rows = $this->repository->findWithWinnings(1);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['total_amount'] ?? null, 'a left join, so an unevaluated draw is still listed');
    }

    public function testTotalWinningsSumsTheYear(): void
    {
        self::assertSame(0.0, $this->repository->totalWinnings(1));

        $first = $this->givenDraw(1, '2026-01-07');
        $first->recordWinnings(1, 100.00);
        $this->repository->save($first);

        $second = $this->givenDraw(2, '2026-01-10');
        $second->recordWinnings(1, 23.45);
        $this->repository->save($second);

        self::assertSame(123.45, $this->repository->totalWinnings(1));
    }

    public function testRowMatchesArePersistedAndOverwrittenOnRecalculation(): void
    {
        $this->givenDraw(1, '2026-01-07');

        $this->repository->saveRowMatches(1, [
            [
                'ticketRowId' => 1,
                'matchedNumbers' => 4,
                'superzahlMatched' => true,
                'winningClass' => 5,
                'amount' => 123.45,
            ],
        ]);

        $row = $this->db->fetchOne('SELECT * FROM ticket_row_match WHERE ticket_row_id = 1 AND draw_id = 1');
        self::assertNotNull($row);
        self::assertSame(4, Row::int($row, 'matched_numbers'));
        self::assertSame(5, Row::int($row, 'winning_class'));
        self::assertTrue(Row::bool($row, 'superzahl_matched'));

        // Recalculating must update in place, not add a second match
        $this->repository->saveRowMatches(1, [
            [
                'ticketRowId' => 1,
                'matchedNumbers' => 3,
                'superzahlMatched' => false,
                'winningClass' => null,
                'amount' => 0.0,
            ],
        ]);

        $all = $this->db->fetchAll('SELECT * FROM ticket_row_match WHERE draw_id = 1');
        self::assertCount(1, $all);
        self::assertSame(3, Row::int($all[0], 'matched_numbers'));
        self::assertNull($all[0]['winning_class']);
    }

    public function testEvaluatingARowAgainstTheDraw(): void
    {
        $draw = $this->givenDraw(1, '2026-01-07');

        $result = $draw->evaluate(new LottoNumbers([3, 12, 19, 27, 33, 45]), new Superzahl(7));

        self::assertSame(4, $result['matchedNumbers']);
        self::assertTrue($result['superzahlMatched']);
    }

    public function testFindByDateAndByTippYear(): void
    {
        $this->givenDraw(1, '2026-01-07');
        $this->givenDraw(2, '2026-01-10');

        self::assertNotNull($this->repository->findByDate(new DateTimeImmutable('2026-01-07')));
        self::assertNull($this->repository->findByDate(new DateTimeImmutable('2026-01-08')));
        self::assertCount(2, $this->repository->findByTippYear(1));
    }
}
