<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Presentation;

use BettingGame\Application\Command\CreateBettingGameHandler;
use BettingGame\Application\Command\EndGameHandler;
use BettingGame\Application\Query\BettingGameReadModel;
use BettingGame\Application\Query\BettingGameReadModelRepositoryInterface;
use BettingGame\Application\Query\GetAllGamesHandler;
use BettingGame\Application\Query\GetGameDetailsHandler;
use BettingGame\Domain\Model\BettingGame;
use BettingGame\Domain\Repository\BettingGameRepositoryInterface;
use BettingGame\Presentation\Controller\AdminGameController;
use BettingGame\Presentation\Http\Request;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdminGameControllerTest extends TestCase
{
    private BettingGameRepositoryInterface&MockObject $gameRepo;
    private BettingGameReadModelRepositoryInterface&MockObject $readModelRepo;
    private AdminGameController $controller;

    protected function setUp(): void
    {
        $this->gameRepo = $this->createMock(BettingGameRepositoryInterface::class);
        $this->readModelRepo = $this->createMock(BettingGameReadModelRepositoryInterface::class);

        $this->controller = new AdminGameController(
            new CreateBettingGameHandler($this->gameRepo),
            new EndGameHandler($this->gameRepo),
            new GetAllGamesHandler($this->readModelRepo),
            new GetGameDetailsHandler($this->readModelRepo)
        );
    }

    public function testListingGamesReturns200(): void
    {
        $this->readModelRepo->method('findAll')->willReturn([$this->readModel()]);

        $response = $this->controller->getAllGames($this->request(), []);

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, $response->data()['games']);
    }

    public function testFiltersArePassedThrough(): void
    {
        $this->readModelRepo->expects(self::once())
            ->method('findAll')
            ->with('upcoming', 1)
            ->willReturn([]);

        $this->controller->getAllGames(
            $this->request(query: ['status' => 'upcoming', 'gameTypeId' => '1']),
            []
        );
    }

    public function testGameDetailsReturn200(): void
    {
        $this->readModelRepo->method('findById')->willReturn($this->readModel());

        $response = $this->controller->getGameDetails($this->request(), ['bettingGameId' => '5']);

        self::assertSame(200, $response->statusCode());
        self::assertSame('Test Cup', $response->data()['name']);
    }

    public function testGameDetailsForAnUnknownGameReturn404(): void
    {
        $this->readModelRepo->method('findById')->willReturn(null);

        $response = $this->controller->getGameDetails($this->request(), ['bettingGameId' => '999']);

        self::assertSame(404, $response->statusCode());
    }

    public function testCreatingAGameReturns202(): void
    {
        $this->gameRepo->method('nextIdentity')->willReturn(7);
        $this->gameRepo->expects(self::once())->method('save');

        $response = $this->controller->createGame($this->request(body: [
            'name' => 'Test Cup',
            'description' => 'A cup',
            'gameTypeId' => 1,
            'startDate' => '2026-01-01 00:00:00',
            'endDate' => '2026-12-31 00:00:00',
        ]), []);

        self::assertSame(202, $response->statusCode());
        self::assertSame('7', $response->data()['resourceId']);
    }

    public function testCreatingWithoutRequiredFieldsReturns400(): void
    {
        $this->gameRepo->expects(self::never())->method('save');

        $response = $this->controller->createGame($this->request(body: ['name' => 'Test Cup']), []);

        self::assertSame(400, $response->statusCode());
    }

    public function testCreatingWithAnEndDateBeforeTheStartReturns400(): void
    {
        $this->gameRepo->method('nextIdentity')->willReturn(7);

        $response = $this->controller->createGame($this->request(body: [
            'name' => 'Test Cup',
            'description' => 'A cup',
            'gameTypeId' => 1,
            'startDate' => '2026-12-31 00:00:00',
            'endDate' => '2026-01-01 00:00:00',
        ]), []);

        self::assertSame(400, $response->statusCode());
    }

    public function testCreatingWithANonObjectConfigurationReturns400(): void
    {
        $response = $this->controller->createGame($this->request(body: [
            'name' => 'Test Cup',
            'description' => 'A cup',
            'gameTypeId' => 1,
            'startDate' => '2026-01-01 00:00:00',
            'endDate' => '2026-12-31 00:00:00',
            'pointConfiguration' => 'five points',
        ]), []);

        self::assertSame(400, $response->statusCode());
    }

    public function testEndingAGameReturns202(): void
    {
        $this->gameRepo->method('findById')->willReturn($this->game());
        $this->gameRepo->expects(self::once())->method('save');

        $response = $this->controller->endGame(
            $this->request(body: ['reason' => 'Season over']),
            ['bettingGameId' => '5']
        );

        self::assertSame(202, $response->statusCode());
    }

    public function testEndingWithoutAReasonReturns400(): void
    {
        $this->gameRepo->expects(self::never())->method('save');

        $response = $this->controller->endGame($this->request(body: []), ['bettingGameId' => '5']);

        self::assertSame(400, $response->statusCode());
    }

    public function testEndingAnUnknownGameReturns400(): void
    {
        $this->gameRepo->method('findById')->willReturn(null);

        $response = $this->controller->endGame(
            $this->request(body: ['reason' => 'Season over']),
            ['bettingGameId' => '999']
        );

        self::assertSame(400, $response->statusCode());
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function request(array $query = [], array $body = []): Request
    {
        return new Request(
            'GET',
            '/',
            [],
            $query,
            $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    private function readModel(): BettingGameReadModel
    {
        return new BettingGameReadModel(
            bettingGameId: 5,
            name: 'Test Cup',
            description: 'A cup',
            gameType: ['gameTypeId' => 1, 'typeName' => 'Football', 'category' => 'sports'],
            status: 'upcoming',
            startDate: '2026-01-01 00:00:00',
            endDate: '2026-12-31 00:00:00',
            baseFee: 10.0,
            feePeriodDays: 30,
            participantCount: 2,
            eventCount: 1,
            configuration: ['pointsExactMatch' => 5],
            createdAt: '2026-01-01 00:00:00'
        );
    }

    private function game(): BettingGame
    {
        return BettingGame::create(
            5,
            'Test Cup',
            'A cup',
            1,
            new DateTimeImmutable('2026-01-01'),
            new DateTimeImmutable('2026-12-31')
        );
    }
}
