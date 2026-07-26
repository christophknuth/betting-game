<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Presentation;

use BettingGame\Presentation\Controller\PredictionController;
use BettingGame\Application\Command\SubmitPredictionHandler;
use BettingGame\Application\Command\UpdatePredictionHandler;
use BettingGame\Application\Command\CommandResult;
use BettingGame\Application\Query\GetParticipantPredictionsHandler;
use BettingGame\Application\Query\QueryResult;
use BettingGame\Presentation\Http\Request;
use BettingGame\Domain\Exception\EntityNotFoundException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class PredictionControllerTest extends TestCase
{
    private SubmitPredictionHandler&MockObject $submitHandler;
    private UpdatePredictionHandler&MockObject $updateHandler;
    private GetParticipantPredictionsHandler&MockObject $getHandler;
    private PredictionController $controller;

    protected function setUp(): void
    {
        $this->submitHandler = $this->createMock(SubmitPredictionHandler::class);
        $this->updateHandler = $this->createMock(UpdatePredictionHandler::class);
        $this->getHandler = $this->createMock(GetParticipantPredictionsHandler::class);

        $this->controller = new PredictionController(
            $this->submitHandler,
            $this->updateHandler,
            $this->getHandler
        );
    }

    public function testGetPredictionsSuccess(): void
    {
        $request = $this->createRequest('GET', '/participants/1/predictions');
        $request->setAttribute('participant_id', 1);

        $this->getHandler->expects($this->once())
            ->method('handle')
            ->willReturn(new QueryResult([
                'predictions' => [],
                'totalCount' => 0,
            ]));

        $response = $this->controller->getPredictions($request, ['participantId' => '1']);

        $this->assertEquals(200, $response->statusCode());
    }

    public function testGetPredictionsUnauthorized(): void
    {
        $request = $this->createRequest('GET', '/participants/1/predictions');
        $request->setAttribute('participant_id', 2); // Different participant

        $response = $this->controller->getPredictions($request, ['participantId' => '1']);

        $this->assertEquals(403, $response->statusCode());
    }

    public function testSubmitPredictionSuccess(): void
    {
        $request = $this->createRequest(
            'POST',
            '/participants/1/events/100/predictions',
            ['predictionData' => ['homeScore' => 2, 'awayScore' => 1]]
        );
        $request->setAttribute('participant_id', 1);

        $this->submitHandler->expects($this->once())
            ->method('handle')
            ->willReturn(new CommandResult(
                commandId: 'cmd-123',
                status: 'accepted',
                resourceId: 'pred-123'
            ));

        $response = $this->controller->submitPrediction(
            $request,
            ['participantId' => '1', 'eventId' => '100']
        );

        $this->assertEquals(202, $response->statusCode());
        $data = $response->data();
        $this->assertEquals('accepted', $data['status']);
        $this->assertEquals('pred-123', $data['resourceId']);
    }

    public function testSubmitPredictionMissingData(): void
    {
        $request = $this->createRequest(
            'POST',
            '/participants/1/events/100/predictions',
            [] // Missing predictionData
        );
        $request->setAttribute('participant_id', 1);

        $response = $this->controller->submitPrediction(
            $request,
            ['participantId' => '1', 'eventId' => '100']
        );

        $this->assertEquals(400, $response->statusCode());
    }

    public function testSubmitPredictionUnauthorized(): void
    {
        $request = $this->createRequest(
            'POST',
            '/participants/1/events/100/predictions',
            ['predictionData' => ['homeScore' => 2]]
        );
        $request->setAttribute('participant_id', 2); // Different participant

        $response = $this->controller->submitPrediction(
            $request,
            ['participantId' => '1', 'eventId' => '100']
        );

        $this->assertEquals(403, $response->statusCode());
    }

    public function testSubmitPredictionEntityNotFound(): void
    {
        $request = $this->createRequest(
            'POST',
            '/participants/1/events/999/predictions',
            ['predictionData' => ['homeScore' => 2]]
        );
        $request->setAttribute('participant_id', 1);

        $this->submitHandler->expects($this->once())
            ->method('handle')
            ->willThrowException(new EntityNotFoundException('Event not found'));

        $response = $this->controller->submitPrediction(
            $request,
            ['participantId' => '1', 'eventId' => '999']
        );

        $this->assertEquals(400, $response->statusCode());
    }

    public function testUpdatePredictionSuccess(): void
    {
        $request = $this->createRequest(
            'PUT',
            '/participants/1/predictions/pred-123',
            ['predictionData' => ['homeScore' => 3]]
        );
        $request->setAttribute('participant_id', 1);

        $this->updateHandler->expects($this->once())
            ->method('handle')
            ->willReturn(new CommandResult(
                commandId: 'cmd-456',
                status: 'accepted',
                resourceId: 'pred-123'
            ));

        $response = $this->controller->updatePrediction(
            $request,
            ['participantId' => '1', 'predictionId' => 'pred-123']
        );

        $this->assertEquals(202, $response->statusCode());
    }

    private function createRequest(string $method, string $uri, array $body = []): Request
    {
        return new Request(
            $method,
            $uri,
            [],
            [],
            !empty($body) ? json_encode($body) : null
        );
    }
}
