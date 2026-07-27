<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\AwardScoreCommand;
use BettingGame\Application\Command\AwardScoreHandler;
use BettingGame\Application\Command\CalculateScoresCommand;
use BettingGame\Application\Command\CalculateScoresHandler;
use BettingGame\Application\Command\RecordResultCommand;
use BettingGame\Application\Command\RecordResultHandler;
use BettingGame\Application\Command\UpdateResultCommand;
use BettingGame\Application\Command\UpdateResultHandler;
use BettingGame\Domain\Exception\DomainException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

final class AdminResultController
{
    public function __construct(
        private RecordResultHandler $recordResultHandler,
        private UpdateResultHandler $updateResultHandler,
        private CalculateScoresHandler $calculateScoresHandler,
        private AwardScoreHandler $awardScoreHandler
    ) {
    }

    public function recordResult(Request $request, array $params): JsonResponse
    {
        $eventId = (int) $params['eventId'];
        $body = $request->jsonBody();

        if (!isset($body['resultData'])) {
            return JsonResponse::badRequest('resultData is required');
        }

        $command = new RecordResultCommand(
            eventId: $eventId,
            resultData: $body['resultData'],
            source: $body['source'] ?? null,
            correlationId: $request->header('X-Correlation-ID')
        );

        try {
            $result = $this->recordResultHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    public function updateResult(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        if (!isset($body['resultData'])) {
            return JsonResponse::badRequest('resultData is required');
        }

        $command = new UpdateResultCommand(
            eventId: (int) $params['eventId'],
            resultData: $body['resultData'],
            reason: $body['reason'] ?? null,
            correlationId: $request->header('X-Correlation-ID')
        );

        try {
            $result = $this->updateResultHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (EntityNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    public function calculateScores(Request $request, array $params): JsonResponse
    {
        $command = new CalculateScoresCommand(
            eventId: (int) $params['eventId'],
            correlationId: $request->header('X-Correlation-ID')
        );

        try {
            $result = $this->calculateScoresHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (EntityNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    public function awardScore(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        if (!isset($body['bettingGameId'], $body['eventId'])) {
            return JsonResponse::badRequest('bettingGameId and eventId are required');
        }

        if (!isset($body['pointsEarned']) && !isset($body['prizeAmount'])) {
            return JsonResponse::badRequest('Either pointsEarned or prizeAmount is required');
        }

        $command = new AwardScoreCommand(
            participantId: (int) $params['participantId'],
            bettingGameId: (int) $body['bettingGameId'],
            eventId: (int) $body['eventId'],
            pointsEarned: isset($body['pointsEarned']) ? (int) $body['pointsEarned'] : null,
            prizeAmount: isset($body['prizeAmount']) ? (float) $body['prizeAmount'] : null,
            reason: $body['reason'] ?? null,
            correlationId: $request->header('X-Correlation-ID')
        );

        try {
            $result = $this->awardScoreHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (EntityNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }
}
