<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Presentation;

use BettingGame\Presentation\Router\Router;
use FastRoute\Dispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, string>}>
     */
    public static function routeProvider(): array
    {
        return [
            // Participant - Predictions
            'list predictions' => [
                'GET', '/participants/1/predictions',
                'PredictionController', 'getPredictions', ['participantId' => '1'],
            ],
            'single prediction' => [
                'GET', '/participants/1/predictions/7',
                'PredictionController', 'getPrediction', ['participantId' => '1', 'predictionId' => '7'],
            ],
            'submit prediction' => [
                'POST', '/participants/1/events/42/predictions',
                'PredictionController', 'submitPrediction', ['participantId' => '1', 'eventId' => '42'],
            ],
            'update prediction' => [
                'PUT', '/participants/1/predictions/7',
                'PredictionController', 'updatePrediction', ['participantId' => '1', 'predictionId' => '7'],
            ],

            // Participant - Scores
            'own scores' => [
                'GET', '/participants/1/scores',
                'ScoreController', 'getScores', ['participantId' => '1'],
            ],
            'leaderboard' => [
                'GET', '/participants/1/games/5/leaderboard',
                'ScoreController', 'getLeaderboard', ['participantId' => '1', 'bettingGameId' => '5'],
            ],

            // Participant - Participation
            'participations' => [
                'GET', '/participants/1/participations',
                'ParticipationController', 'getParticipations', ['participantId' => '1'],
            ],
            'join game' => [
                'POST', '/participants/1/games/5/participation',
                'ParticipationController', 'joinGame', ['participantId' => '1', 'bettingGameId' => '5'],
            ],
            'leave game' => [
                'DELETE', '/participants/1/games/5/participation',
                'ParticipationController', 'leaveGame', ['participantId' => '1', 'bettingGameId' => '5'],
            ],

            // Admin
            'all predictions' => [
                'GET', '/admin/predictions',
                'AdminPredictionController', 'getAllPredictions', [],
            ],
            'all games' => [
                'GET', '/admin/games',
                'AdminGameController', 'getAllGames', [],
            ],
            'create game' => [
                'POST', '/admin/games',
                'AdminGameController', 'createGame', [],
            ],
            'end game' => [
                'POST', '/admin/games/5/end',
                'AdminGameController', 'endGame', ['bettingGameId' => '5'],
            ],
            'record result' => [
                'POST', '/admin/events/42/results',
                'AdminResultController', 'recordResult', ['eventId' => '42'],
            ],
            'update result' => [
                'PUT', '/admin/events/42/results',
                'AdminResultController', 'updateResult', ['eventId' => '42'],
            ],
            'calculate scores' => [
                'POST', '/admin/events/42/scores/calculate',
                'AdminResultController', 'calculateScores', ['eventId' => '42'],
            ],
            'award score' => [
                'POST', '/admin/participants/1/scores',
                'AdminResultController', 'awardScore', ['participantId' => '1'],
            ],
            'create participant' => [
                'POST', '/admin/participants',
                'AdminParticipantController', 'createParticipant', [],
            ],
            'approve participant' => [
                'POST', '/admin/participants/1/approve',
                'AdminParticipantController', 'approveParticipant', ['participantId' => '1'],
            ],

            'health' => [
                'GET', '/health',
                'HealthController', 'check', [],
            ],
        ];
    }

    #[DataProvider('routeProvider')]
    public function testRouteDispatchesToController(
        string $method,
        string $uri,
        string $controller,
        string $handlerMethod,
        array $expectedVars
    ): void {
        $routeInfo = $this->router->dispatch($method, $uri);

        $this->assertSame(Dispatcher::FOUND, $routeInfo[0], "Route $method $uri was not found");
        $this->assertSame($controller, $routeInfo[1]['controller']);
        $this->assertSame($handlerMethod, $routeInfo[1]['method']);
        $this->assertSame($expectedVars, $routeInfo[2]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function adminRouteProvider(): array
    {
        return [
            'all predictions' => ['GET', '/admin/predictions'],
            'all games' => ['GET', '/admin/games'],
            'create game' => ['POST', '/admin/games'],
            'end game' => ['POST', '/admin/games/5/end'],
            'record result' => ['POST', '/admin/events/42/results'],
            'update result' => ['PUT', '/admin/events/42/results'],
            'calculate scores' => ['POST', '/admin/events/42/scores/calculate'],
            'award score' => ['POST', '/admin/participants/1/scores'],
            'create participant' => ['POST', '/admin/participants'],
            'approve participant' => ['POST', '/admin/participants/1/approve'],
        ];
    }

    #[DataProvider('adminRouteProvider')]
    public function testAdminRoutesRequireAdminRole(string $method, string $uri): void
    {
        $routeInfo = $this->router->dispatch($method, $uri);

        $this->assertSame(Dispatcher::FOUND, $routeInfo[0]);
        $this->assertSame('admin', $routeInfo[1]['role'] ?? null, "Route $method $uri is not admin protected");
    }

    public function testParticipantRoutesAreNotAdminProtected(): void
    {
        $routeInfo = $this->router->dispatch('GET', '/participants/1/games/5/leaderboard');

        $this->assertSame(Dispatcher::FOUND, $routeInfo[0]);
        $this->assertArrayNotHasKey('role', $routeInfo[1]);
    }

    public function testUnknownRouteIsNotFound(): void
    {
        $routeInfo = $this->router->dispatch('GET', '/admin/does-not-exist');

        $this->assertSame(Dispatcher::NOT_FOUND, $routeInfo[0]);
    }

    public function testNonNumericParticipantIdDoesNotMatch(): void
    {
        $routeInfo = $this->router->dispatch('GET', '/participants/abc/scores');

        $this->assertSame(Dispatcher::NOT_FOUND, $routeInfo[0]);
    }
}
