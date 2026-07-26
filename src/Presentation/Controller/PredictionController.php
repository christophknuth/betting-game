<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\SubmitPredictionCommand;
use BettingGame\Application\Command\SubmitPredictionHandler;
use BettingGame\Application\Command\UpdatePredictionCommand;
use BettingGame\Application\Command\UpdatePredictionHandler;
use BettingGame\Application\Query\GetParticipantPredictionsQuery;
use BettingGame\Application\Query\GetParticipantPredictionsHandler;
use BettingGame\Domain\Exception\DomainException;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

final class PredictionController
{
    public function __construct(
        private SubmitPredictionHandler $submitHandler,
        private UpdatePredictionHandler $updateHandler,
        private GetParticipantPredictionsHandler $getPredictionsHandler
    ) {
    }

    public function getPredictions(Request $request, array $params): JsonResponse
    {
        $participantId = (int) $params['participantId'];
        
        // Authorization check (simplified - would use JWT in production)
        if (!$this->isAuthorized($request, $participantId)) {
            return JsonResponse::forbidden('Access denied');
        }

        $query = new GetParticipantPredictionsQuery(
            participantId: $participantId,
            bettingGameId: $request->queryParam('bettingGameId') ? (int) $request->queryParam('bettingGameId') : null,
            eventId: $request->queryParam('eventId') ? (int) $request->queryParam('eventId') : null,
            status: $request->queryParam('status')
        );

        try {
            $result = $this->getPredictionsHandler->handle($query);
            return JsonResponse::ok($result->data());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    public function submitPrediction(Request $request, array $params): JsonResponse
    {
        $participantId = (int) $params['participantId'];
        $eventId = (int) $params['eventId'];

        if (!$this->isAuthorized($request, $participantId)) {
            return JsonResponse::forbidden('Access denied');
        }

        $body = $request->jsonBody();
        
        if (!isset($body['predictionData'])) {
            return JsonResponse::badRequest('predictionData is required');
        }

        $command = new SubmitPredictionCommand(
            participantId: $participantId,
            eventId: $eventId,
            predictionData: $body['predictionData'],
            correlationId: $request->header('X-Correlation-ID')
        );

        try {
            $result = $this->submitHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    public function updatePrediction(Request $request, array $params): JsonResponse
    {
        $participantId = (int) $params['participantId'];
        $predictionId = $params['predictionId'];

        if (!$this->isAuthorized($request, $participantId)) {
            return JsonResponse::forbidden('Access denied');
        }

        $body = $request->jsonBody();
        
        if (!isset($body['predictionData'])) {
            return JsonResponse::badRequest('predictionData is required');
        }

        $command = new UpdatePredictionCommand(
            predictionId: $predictionId,
            participantId: $participantId,
            predictionData: $body['predictionData'],
            correlationId: $request->header('X-Correlation-ID')
        );

        try {
            $result = $this->updateHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    private function isAuthorized(Request $request, int $participantId): bool
    {
        // Simplified authorization - in production would validate JWT
        $authParticipantId = $request->attribute('participant_id');
        return $authParticipantId === $participantId;
    }
}
