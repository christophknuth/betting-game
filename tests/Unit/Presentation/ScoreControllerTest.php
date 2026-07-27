<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Presentation;

use BettingGame\Application\Query\GetLeaderboardHandler;
use BettingGame\Application\Query\GetParticipantScoresHandler;
use BettingGame\Application\Query\LeaderboardReadModel;
use BettingGame\Application\Query\LeaderboardReadModelRepositoryInterface;
use BettingGame\Application\Query\ParticipantScoreReadModel;
use BettingGame\Application\Query\ScoreReadModelRepositoryInterface;
use BettingGame\Presentation\Controller\ScoreController;
use BettingGame\Presentation\Http\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ScoreControllerTest extends TestCase
{
    private ScoreReadModelRepositoryInterface&MockObject $scoreRepo;
    private LeaderboardReadModelRepositoryInterface&MockObject $leaderboardRepo;
    private ScoreController $controller;

    protected function setUp(): void
    {
        $this->scoreRepo = $this->createMock(ScoreReadModelRepositoryInterface::class);
        $this->leaderboardRepo = $this->createMock(LeaderboardReadModelRepositoryInterface::class);

        $this->controller = new ScoreController(
            new GetParticipantScoresHandler($this->scoreRepo),
            new GetLeaderboardHandler($this->leaderboardRepo)
        );
    }

    public function testOwnScoresReturn200WithSummary(): void
    {
        $this->scoreRepo->method('findByParticipant')->willReturn([
            new ParticipantScoreReadModel(
                scoreId: 1,
                participantId: 1,
                bettingGameId: 5,
                bettingGameName: 'Test Cup',
                eventId: 42,
                eventName: 'Final',
                pointsEarned: 12,
                prizeAmount: 4.5,
                calculatedAt: '2026-06-02 10:00:00'
            ),
        ]);
        $this->scoreRepo->method('getSummary')->willReturn([
            'totalPoints' => 12,
            'totalPrizeAmount' => 4.5,
            'gamesParticipated' => 1,
        ]);

        $response = $this->controller->getScores($this->request(), ['participantId' => '1']);

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, $response->data()['scores']);
        self::assertSame(12, $response->data()['summary']['totalPoints']);
    }

    public function testScoresOfAnotherParticipantAreForbidden(): void
    {
        $response = $this->controller->getScores(
            $this->request(authenticatedAs: 2),
            ['participantId' => '1']
        );

        self::assertSame(403, $response->statusCode());
    }

    public function testTheGameFilterIsPassedThrough(): void
    {
        $this->scoreRepo->expects(self::once())
            ->method('findByParticipant')
            ->with(1, 5)
            ->willReturn([]);
        $this->scoreRepo->method('getSummary')->willReturn([]);

        $response = $this->controller->getScores(
            $this->request(query: ['bettingGameId' => '5']),
            ['participantId' => '1']
        );

        self::assertSame(200, $response->statusCode());
    }

    public function testLeaderboardReturns200(): void
    {
        $this->leaderboardRepo->method('getLeaderboard')->willReturn(
            new LeaderboardReadModel(5, 'Test Cup', [['rank' => 1, 'participantId' => 2]], '2026-06-02T11:00:00+00:00')
        );

        $response = $this->controller->getLeaderboard(
            $this->request(),
            ['participantId' => '1', 'bettingGameId' => '5']
        );

        self::assertSame(200, $response->statusCode());
        self::assertSame('Test Cup', $response->data()['bettingGameName']);
    }

    public function testLeaderboardOfAnUnknownGameReturns404(): void
    {
        $this->leaderboardRepo->method('getLeaderboard')->willReturn(null);

        $response = $this->controller->getLeaderboard(
            $this->request(),
            ['participantId' => '1', 'bettingGameId' => '999']
        );

        self::assertSame(404, $response->statusCode());
    }

    public function testLeaderboardForAnotherParticipantIsForbidden(): void
    {
        $response = $this->controller->getLeaderboard(
            $this->request(authenticatedAs: 2),
            ['participantId' => '1', 'bettingGameId' => '5']
        );

        self::assertSame(403, $response->statusCode());
    }

    public function testTheLimitIsPassedThrough(): void
    {
        $this->leaderboardRepo->expects(self::once())
            ->method('getLeaderboard')
            ->with(5, 10)
            ->willReturn(new LeaderboardReadModel(5, 'Test Cup', [], '2026-06-02T11:00:00+00:00'));

        $this->controller->getLeaderboard(
            $this->request(query: ['limit' => '10']),
            ['participantId' => '1', 'bettingGameId' => '5']
        );
    }

    /** @param array<string, mixed> $query */
    private function request(int $authenticatedAs = 1, array $query = []): Request
    {
        $request = new Request('GET', '/', [], $query, null);
        $request->setAttribute('participant_id', $authenticatedAs);

        return $request;
    }
}
