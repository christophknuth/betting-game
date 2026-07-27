<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Presentation\Controller\AdminGameController;
use BettingGame\Presentation\Controller\AdminParticipantController;
use BettingGame\Presentation\Http\Request;
use BettingGame\Presentation\Router\Router;
use FastRoute\Dispatcher;

/**
 * Drives the admin read endpoints through their controllers, so request
 * parsing, status codes and the SQL underneath are all exercised together.
 */
final class AdminEndpointTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBaseData();
        $this->seedGame();
        $this->seedEvent();
        $this->seedParticipant(1, 100, 'Alice');
        $this->seedParticipant(2, 101, 'Bob');
        $this->pdo->exec(
            "INSERT INTO point_configuration
                (betting_game_id, scoring_rule_name, points_exact_match, points_close_match)
             VALUES (5, 'default', 5, 3)"
        );
        $this->pdo->exec(
            "INSERT INTO game_participation (participant_id, betting_game_id, status)
             VALUES (1, 5, 'active'), (2, 5, 'pending_approval')"
        );
        $this->pdo->exec(
            'INSERT INTO participant_score (participant_id, betting_game_id, event_id, points_earned, prize_amount)
             VALUES (1, 5, 42, 12, 4.50)'
        );
    }

    /** @param array<string, mixed> $query */
    private function request(array $query = []): Request
    {
        return new Request('GET', '/', [], $query, null);
    }

    public function testTheAdminRoutesDispatchAndAreRoleProtected(): void
    {
        $router = $this->get(Router::class);

        $expected = [
            ['/admin/games/5', 'AdminGameController', 'getGameDetails'],
            ['/admin/participants', 'AdminParticipantController', 'getAllParticipants'],
            ['/admin/games/5/participants/pending', 'AdminParticipantController', 'getPendingParticipants'],
        ];

        foreach ($expected as [$uri, $controller, $method]) {
            $info = $router->dispatch('GET', $uri);

            self::assertSame(Dispatcher::FOUND, $info[0], "GET $uri was not routed");
            self::assertSame($controller, $info[1]['controller']);
            self::assertSame($method, $info[1]['method']);
            self::assertSame('admin', $info[1]['role'] ?? null, "GET $uri is not admin protected");
        }
    }

    public function testGameDetailsReturnCountsAndConfiguration(): void
    {
        $response = $this->get(AdminGameController::class)
            ->getGameDetails($this->request(), ['bettingGameId' => '5']);

        self::assertSame(200, $response->statusCode());

        $data = $response->data();
        self::assertSame('Test Cup', $data['name']);
        self::assertSame('Football', $data['gameType']['typeName']);
        self::assertSame(2, $data['participantCount'], 'active and pending both count');
        self::assertSame(1, $data['eventCount']);
        self::assertSame(5, $data['configuration']['pointsExactMatch']);
    }

    public function testGameDetailsAnswer404ForAnUnknownGame(): void
    {
        $response = $this->get(AdminGameController::class)
            ->getGameDetails($this->request(), ['bettingGameId' => '999']);

        self::assertSame(404, $response->statusCode());
    }

    public function testParticipantListAggregatesPointsAndDerivesStatus(): void
    {
        $response = $this->get(AdminParticipantController::class)
            ->getAllParticipants($this->request(), []);

        self::assertSame(200, $response->statusCode());

        $participants = $response->data()['participants'];
        self::assertCount(2, $participants);

        $byName = array_column($participants, null, 'displayName');
        self::assertSame(12, $byName['Alice']['totalPoints']);
        self::assertSame(4.5, $byName['Alice']['totalPrizes']);
        self::assertSame(1, $byName['Alice']['gamesParticipated']);
        self::assertSame('active', $byName['Alice']['status']);
        self::assertSame('pending_approval', $byName['Bob']['status'], 'derived from the pending participation');
    }

    public function testParticipantListFiltersByStatusAndGame(): void
    {
        $controller = $this->get(AdminParticipantController::class);

        $pending = $controller->getAllParticipants($this->request(['status' => 'pending_approval']), [])
            ->data()['participants'];
        self::assertCount(1, $pending);
        self::assertSame('Bob', $pending[0]['displayName']);

        self::assertCount(
            2,
            $controller->getAllParticipants($this->request(['bettingGameId' => '5']), [])->data()['participants']
        );
        self::assertCount(
            0,
            $controller->getAllParticipants($this->request(['bettingGameId' => '999']), [])->data()['participants']
        );
    }

    public function testPendingListShowsOnlyAwaitingParticipants(): void
    {
        $controller = $this->get(AdminParticipantController::class);

        $response = $controller->getPendingParticipants($this->request(), ['bettingGameId' => '5']);
        self::assertSame(200, $response->statusCode());

        $pending = $response->data()['pendingParticipants'];
        self::assertCount(1, $pending);
        self::assertSame('Bob', $pending[0]['displayName']);

        $this->pdo->exec("UPDATE game_participation SET status = 'active' WHERE participant_id = 2");

        self::assertSame(
            [],
            $controller->getPendingParticipants($this->request(), ['bettingGameId' => '5'])
                ->data()['pendingParticipants']
        );
    }

    public function testPendingListForAnUnknownGameIsEmptyNotAnError(): void
    {
        $response = $this->get(AdminParticipantController::class)
            ->getPendingParticipants($this->request(), ['bettingGameId' => '999']);

        self::assertSame(200, $response->statusCode(), 'no applications is a valid answer, not a 404');
        self::assertSame([], $response->data()['pendingParticipants']);
    }
}
