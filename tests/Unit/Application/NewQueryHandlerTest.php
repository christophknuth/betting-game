<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Application;

use BettingGame\Application\Query\GetParticipationsQuery;
use BettingGame\Application\Query\GetParticipationsHandler;
use BettingGame\Application\Query\GetLeaderboardQuery;
use BettingGame\Application\Query\GetLeaderboardHandler;
use BettingGame\Application\Query\GetAllPredictionsQuery;
use BettingGame\Application\Query\GetAllPredictionsHandler;
use BettingGame\Application\Query\ParticipationReadModel;
use BettingGame\Application\Query\ParticipationReadModelRepositoryInterface;
use BettingGame\Application\Query\LeaderboardReadModel;
use BettingGame\Application\Query\LeaderboardReadModelRepositoryInterface;
use BettingGame\Application\Query\PredictionReadModel;
use BettingGame\Application\Query\AdminPredictionReadModelRepositoryInterface;
use BettingGame\Domain\Exception\EntityNotFoundException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class NewQueryHandlerTest extends TestCase
{
    // ============================
    // GetParticipationsHandler Tests
    // ============================

    public function testGetParticipationsSuccess(): void
    {
        $repo = $this->createMock(ParticipationReadModelRepositoryInterface::class);

        $participations = [
            new ParticipationReadModel(
                participantId: 1,
                bettingGameId: 5,
                bettingGameName: 'Bundesliga 2024',
                gameType: 'sports',
                status: 'active',
                joinedAt: '2024-01-15 10:00:00',
                startDate: '2024-08-01 00:00:00',
                endDate: '2024-12-31 23:59:59',
                currentPoints: 25,
                currentPrizeAmount: null,
                feesRequired: true,
                feesPaid: true
            ),
        ];

        $repo->expects($this->once())
            ->method('findByParticipant')
            ->with(1, null)
            ->willReturn($participations);

        $handler = new GetParticipationsHandler($repo);
        $query = new GetParticipationsQuery(participantId: 1);
        $result = $handler->handle($query);

        $data = $result->data();
        $this->assertCount(1, $data['participations']);
        $this->assertEquals(5, $data['participations'][0]['bettingGameId']);
        $this->assertEquals('active', $data['participations'][0]['status']);
    }

    public function testGetParticipationsWithStatusFilter(): void
    {
        $repo = $this->createMock(ParticipationReadModelRepositoryInterface::class);

        $repo->expects($this->once())
            ->method('findByParticipant')
            ->with(1, 'active')
            ->willReturn([]);

        $handler = new GetParticipationsHandler($repo);
        $query = new GetParticipationsQuery(participantId: 1, status: 'active');
        $result = $handler->handle($query);

        $data = $result->data();
        $this->assertCount(0, $data['participations']);
    }

    // ============================
    // GetLeaderboardHandler Tests
    // ============================

    public function testGetLeaderboardSuccess(): void
    {
        $repo = $this->createMock(LeaderboardReadModelRepositoryInterface::class);

        $leaderboard = new LeaderboardReadModel(
            bettingGameId: 5,
            bettingGameName: 'Bundesliga 2024',
            rankings: [
                ['rank' => 1, 'participantId' => 1, 'displayName' => 'Player 1', 'totalPoints' => 50],
                ['rank' => 2, 'participantId' => 2, 'displayName' => 'Player 2', 'totalPoints' => 42],
            ],
            updatedAt: '2024-06-15 12:00:00'
        );

        $repo->expects($this->once())
            ->method('getLeaderboard')
            ->with(5, 50)
            ->willReturn($leaderboard);

        $handler = new GetLeaderboardHandler($repo);
        $query = new GetLeaderboardQuery(bettingGameId: 5);
        $result = $handler->handle($query);

        $data = $result->data();
        $this->assertEquals(5, $data['bettingGameId']);
        $this->assertCount(2, $data['rankings']);
        $this->assertEquals(1, $data['rankings'][0]['rank']);
    }

    public function testGetLeaderboardWithCustomLimit(): void
    {
        $repo = $this->createMock(LeaderboardReadModelRepositoryInterface::class);

        $leaderboard = new LeaderboardReadModel(
            bettingGameId: 5,
            bettingGameName: 'Test',
            rankings: [
                ['rank' => 1, 'participantId' => 1, 'displayName' => 'Top', 'totalPoints' => 100],
            ],
            updatedAt: '2024-06-15 12:00:00'
        );

        $repo->expects($this->once())
            ->method('getLeaderboard')
            ->with(5, 10)
            ->willReturn($leaderboard);

        $handler = new GetLeaderboardHandler($repo);
        $query = new GetLeaderboardQuery(bettingGameId: 5, limit: 10);
        $result = $handler->handle($query);

        $this->assertCount(1, $result->data()['rankings']);
    }

    public function testGetLeaderboardNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $repo = $this->createMock(LeaderboardReadModelRepositoryInterface::class);

        $repo->expects($this->once())
            ->method('getLeaderboard')
            ->willReturn(null);

        $handler = new GetLeaderboardHandler($repo);
        $query = new GetLeaderboardQuery(bettingGameId: 999);
        $handler->handle($query);
    }

    // ============================
    // GetAllPredictionsHandler Tests
    // ============================

    public function testGetAllPredictionsSuccess(): void
    {
        $repo = $this->createMock(AdminPredictionReadModelRepositoryInterface::class);

        $predictions = [
            new PredictionReadModel(
                predictionId: 'pred-1',
                participantId: 1,
                eventId: 100,
                eventName: 'Match 1',
                predictionData: ['homeScore' => 2],
                submittedAt: '2024-01-01 10:00:00',
                updatedAt: null,
                deadline: '2024-01-01 18:00:00',
                status: 'submitted',
                isEditable: true
            ),
        ];

        $repo->expects($this->once())
            ->method('findAll')
            ->with(null, null, null, 1, 50)
            ->willReturn($predictions);

        $repo->expects($this->once())
            ->method('countAll')
            ->with(null, null, null)
            ->willReturn(1);

        $handler = new GetAllPredictionsHandler($repo);
        $query = new GetAllPredictionsQuery();
        $result = $handler->handle($query);

        $data = $result->data();
        $this->assertCount(1, $data['predictions']);
        $this->assertEquals(1, $data['pagination']['page']);
        $this->assertEquals(50, $data['pagination']['pageSize']);
        $this->assertEquals(1, $data['pagination']['totalCount']);
        $this->assertEquals(1, $data['pagination']['totalPages']);
    }

    public function testGetAllPredictionsWithFilters(): void
    {
        $repo = $this->createMock(AdminPredictionReadModelRepositoryInterface::class);

        $repo->expects($this->once())
            ->method('findAll')
            ->with(5, 100, 1, 2, 25)
            ->willReturn([]);

        $repo->expects($this->once())
            ->method('countAll')
            ->with(5, 100, 1)
            ->willReturn(0);

        $handler = new GetAllPredictionsHandler($repo);
        $query = new GetAllPredictionsQuery(
            bettingGameId: 5,
            eventId: 100,
            participantId: 1,
            page: 2,
            pageSize: 25
        );
        $result = $handler->handle($query);

        $data = $result->data();
        $this->assertCount(0, $data['predictions']);
        $this->assertEquals(0, $data['pagination']['totalCount']);
    }

    public function testGetAllPredictionsPagination(): void
    {
        $repo = $this->createMock(AdminPredictionReadModelRepositoryInterface::class);

        $repo->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $repo->expects($this->once())
            ->method('countAll')
            ->willReturn(120);

        $handler = new GetAllPredictionsHandler($repo);
        $query = new GetAllPredictionsQuery(page: 1, pageSize: 50);
        $result = $handler->handle($query);

        $data = $result->data();
        $this->assertEquals(3, $data['pagination']['totalPages']);
        $this->assertEquals(120, $data['pagination']['totalCount']);
    }
}
