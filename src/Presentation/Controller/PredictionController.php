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
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\InvalidInputException;
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

    /** @param array<string, string> $params */
    public function getPredictions(Request $request, array $params): JsonResponse
    {
        $participantId = (int) $params['participantId'];
        
        // Authorization check (simplified - would use JWT in production)
        if (!$this->isAuthorized($request, $participantId)) {
            return JsonResponse::forbidden('Access denied');
        }

        $bettingGameId = $request->queryParam('bettingGameId');
        $eventId = $request->queryParam('eventId');

        $query = new GetParticipantPredictionsQuery(
            participantId: $participantId,
            bettingGameId: $bettingGameId !== null ? (int) $bettingGameId : null,
            eventId: $eventId !== null ? (int) $eventId : null,
            status: $request->queryParam('status')
        );

        try {
            $result = $this->getPredictionsHandler->handle($query);
            return JsonResponse::ok($result->data());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    /** @param array<string, string> $params */
    public function submitPrediction(Request $request, array $params): JsonResponse
    {
        $participantId = (int) $params['participantId'];
        $eventId = (int) $params['eventId'];

        if (!$this->isAuthorized($request, $participantId)) {
            return JsonResponse::forbidden('Access denied');
        }

        $body = $request->jsonBody();

        try {
            $command = new SubmitPredictionCommand(
                participantId: $participantId,
                eventId: $eventId,
                predictionData: Input::array($body, 'predictionData'),
                correlationId: $request->header('X-Correlation-ID')
            );
        } catch (InvalidInputException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }

        try {
            $result = $this->submitHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    /** @param array<string, string> $params */
    public function updatePrediction(Request $request, array $params): JsonResponse
    {
        $participantId = (int) $params['participantId'];
        $predictionId = $params['predictionId'];

        if (!$this->isAuthorized($request, $participantId)) {
            return JsonResponse::forbidden('Access denied');
        }

        $body = $request->jsonBody();

        try {
            $command = new UpdatePredictionCommand(
                predictionId: $predictionId,
                participantId: $participantId,
                predictionData: Input::array($body, 'predictionData'),
                correlationId: $request->header('X-Correlation-ID')
            );
        } catch (InvalidInputException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }

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
