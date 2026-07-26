<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Application;

use BettingGame\Application\Query\GetParticipantPredictionsQuery;
use BettingGame\Application\Query\GetParticipantPredictionsHandler;
use BettingGame\Application\Query\PredictionReadModelRepositoryInterface;
use BettingGame\Application\Query\PredictionReadModel;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class QueryHandlerTest extends TestCase
{
    private PredictionReadModelRepositoryInterface&MockObject $readModelRepo;

    protected function setUp(): void
    {
        $this->readModelRepo = $this->createMock(PredictionReadModelRepositoryInterface::class);
    }

    public function testGetParticipantPredictionsSuccess(): void
    {
        $query = new GetParticipantPredictionsQuery(
            participantId: 1,
            bettingGameId: null,
            eventId: null,
            status: null
        );

        $readModels = [
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
            new PredictionReadModel(
                predictionId: 'pred-2',
                participantId: 1,
                eventId: 101,
                eventName: 'Match 2',
                predictionData: ['homeScore' => 1],
                submittedAt: '2024-01-02 10:00:00',
                updatedAt: null,
                deadline: '2024-01-02 18:00:00',
                status: 'submitted',
                isEditable: true
            ),
        ];

        $this->readModelRepo->expects($this->once())
            ->method('findByParticipant')
            ->with(1, null, null, null)
            ->willReturn($readModels);

        $handler = new GetParticipantPredictionsHandler($this->readModelRepo);
        $result = $handler->handle($query);

        $data = $result->data();
        $this->assertCount(2, $data['predictions']);
        $this->assertEquals(2, $data['totalCount']);
    }

    public function testGetParticipantPredictionsWithFilters(): void
    {
        $query = new GetParticipantPredictionsQuery(
            participantId: 1,
            bettingGameId: 5,
            eventId: 100,
            status: 'evaluated'
        );

        $this->readModelRepo->expects($this->once())
            ->method('findByParticipant')
            ->with(1, 5, 100, 'evaluated')
            ->willReturn([]);

        $handler = new GetParticipantPredictionsHandler($this->readModelRepo);
        $result = $handler->handle($query);

        $data = $result->data();
        $this->assertCount(0, $data['predictions']);
        $this->assertEquals(0, $data['totalCount']);
    }
}
