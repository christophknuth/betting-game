<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Application\Command\CreateBettingGameCommand;
use BettingGame\Application\Command\CreateBettingGameHandler;
use BettingGame\Application\Command\EndGameCommand;
use BettingGame\Application\Command\EndGameHandler;
use BettingGame\Application\Query\GetAllGamesHandler;
use BettingGame\Application\Query\GetAllGamesQuery;
use BettingGame\Application\Query\GetGameDetailsHandler;
use BettingGame\Application\Query\GetGameDetailsQuery;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;

final class BettingGameFlowTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBaseData();
    }

    private function createGame(): int
    {
        $result = $this->get(CreateBettingGameHandler::class)->handle(new CreateBettingGameCommand(
            name: 'Test Cup',
            description: 'A cup',
            gameTypeId: 1,
            startDate: '2026-01-01 00:00:00',
            endDate: '2026-12-31 00:00:00',
            baseFee: 10.0,
            feePeriodDays: 30,
            pointConfiguration: [
                'pointsExactMatch' => 5,
                'pointsCloseMatch' => 3,
                'pointsPartialMatch' => 1,
                'pointsWrong' => 0,
            ]
        ));

        return (int) $result->resourceId;
    }

    public function testCreatingAGameWritesProjectionConfigurationAndEvent(): void
    {
        $gameId = $this->createGame();
        self::assertSame(1, $gameId);

        $game = $this->fetchRow('SELECT * FROM betting_game WHERE betting_game_id = 1');
        self::assertSame('Test Cup', $game['name']);
        self::assertSame('upcoming', $game['status']);
        self::assertSame(10.0, (float) $game['base_fee']);
        self::assertSame(1, (int) $game['version']);

        $config = $this->fetchRow('SELECT * FROM point_configuration WHERE betting_game_id = 1');
        self::assertSame(5, (int) $config['points_exact_match']);
        self::assertSame(3, (int) $config['points_close_match']);

        $event = $this->fetchRow("SELECT * FROM event_store WHERE aggregate_type = 'betting_game'");
        self::assertSame('betting_game.created', $event['event_type']);
    }

    public function testALotteryGameStoresAPrizeDistributionInstead(): void
    {
        $this->get(CreateBettingGameHandler::class)->handle(new CreateBettingGameCommand(
            name: 'Lotto',
            description: 'Draw',
            gameTypeId: 2,
            startDate: '2026-01-01 00:00:00',
            endDate: '2026-12-31 00:00:00',
            prizeDistribution: [
                'totalPrizePool' => 1000.0,
                'distributionSchema' => 'percentage',
                'rankPercentages' => [['rank' => 1, 'percentage' => 100]],
                'minWinners' => 1,
            ]
        ));

        $distribution = $this->fetchRow('SELECT * FROM prize_distribution WHERE betting_game_id = 1');
        self::assertSame(1000.0, (float) $distribution['total_prize_pool']);
        self::assertSame(0, $this->countRows('point_configuration'));
    }

    public function testEndingAGameSetsTheStatusAndAppendsAnEvent(): void
    {
        $this->createGame();

        $this->get(EndGameHandler::class)->handle(new EndGameCommand(1, 'Season over', true));

        $game = $this->fetchRow('SELECT * FROM betting_game WHERE betting_game_id = 1');
        self::assertSame('ended', $game['status']);
        self::assertSame(2, (int) $game['version']);

        $events = $this->fetchAll(
            "SELECT event_type FROM event_store WHERE aggregate_type = 'betting_game' ORDER BY version"
        );
        self::assertCount(2, $events);
        self::assertSame('betting_game.ended', $events[1]['event_type']);
    }

    public function testEndingAGameTwiceViolatesABusinessRule(): void
    {
        $this->createGame();
        $this->get(EndGameHandler::class)->handle(new EndGameCommand(1, 'Season over'));

        $this->expectException(BusinessRuleViolationException::class);
        $this->get(EndGameHandler::class)->handle(new EndGameCommand(1, 'again'));
    }

    public function testEndingAnUnknownGameFails(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->get(EndGameHandler::class)->handle(new EndGameCommand(999, 'nope'));
    }

    public function testGameListCarriesTypeCountsAndConfiguration(): void
    {
        $this->createGame();
        $this->seedEvent(gameId: 1);
        $this->seedParticipant();
        $this->pdo->exec(
            "INSERT INTO game_participation (participant_id, betting_game_id, status) VALUES (1, 1, 'active')"
        );

        $data = $this->get(GetAllGamesHandler::class)->handle(new GetAllGamesQuery())->data();
        $game = $data['games'][0];

        self::assertSame('Football', $game['gameType']['typeName']);
        self::assertSame(1, $game['eventCount']);
        self::assertSame(1, $game['participantCount']);
        self::assertSame(5, $game['configuration']['pointsExactMatch']);
    }

    public function testAGameWithoutParticipantsOrEventsStillAppears(): void
    {
        $this->createGame();

        $data = $this->get(GetAllGamesHandler::class)->handle(new GetAllGamesQuery())->data();

        self::assertCount(1, $data['games'], 'counts are subqueries, so an empty game is not joined away');
        self::assertSame(0, $data['games'][0]['participantCount']);
        self::assertSame(0, $data['games'][0]['eventCount']);
    }

    public function testGameListFiltersByStatusAndType(): void
    {
        $this->createGame();
        $handler = $this->get(GetAllGamesHandler::class);

        self::assertCount(1, $handler->handle(new GetAllGamesQuery(status: 'upcoming'))->data()['games']);
        self::assertCount(0, $handler->handle(new GetAllGamesQuery(status: 'ended'))->data()['games']);
        self::assertCount(1, $handler->handle(new GetAllGamesQuery(gameTypeId: 1))->data()['games']);
        self::assertCount(0, $handler->handle(new GetAllGamesQuery(gameTypeId: 2))->data()['games']);
    }

    public function testGameDetailsReturnTheSingleGame(): void
    {
        $this->createGame();

        $data = $this->get(GetGameDetailsHandler::class)->handle(new GetGameDetailsQuery(1))->data();

        self::assertSame(1, $data['bettingGameId']);
        self::assertSame('Test Cup', $data['name']);
    }

    public function testGameDetailsForAnUnknownGameFail(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->get(GetGameDetailsHandler::class)->handle(new GetGameDetailsQuery(999));
    }
}
