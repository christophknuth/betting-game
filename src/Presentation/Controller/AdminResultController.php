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
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\InvalidInputException;
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

    /** @param array<string, string> $params */
    public function recordResult(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        try {
            $command = new RecordResultCommand(
                eventId: (int) $params['eventId'],
                resultData: Input::array($body, 'resultData'),
                source: Input::optionalString($body, 'source'),
                correlationId: $request->header('X-Correlation-ID')
            );
        } catch (InvalidInputException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }

        try {
            $result = $this->recordResultHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (EntityNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    /** @param array<string, string> $params */
    public function updateResult(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        try {
            $command = new UpdateResultCommand(
                eventId: (int) $params['eventId'],
                resultData: Input::array($body, 'resultData'),
                reason: Input::optionalString($body, 'reason'),
                correlationId: $request->header('X-Correlation-ID')
            );
        } catch (InvalidInputException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }

        try {
            $result = $this->updateResultHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (EntityNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    /** @param array<string, string> $params */
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

    /** @param array<string, string> $params */
    public function awardScore(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        try {
            $pointsEarned = Input::optionalInt($body, 'pointsEarned');
            $prizeAmount = Input::optionalFloat($body, 'prizeAmount');

            if ($pointsEarned === null && $prizeAmount === null) {
                return JsonResponse::badRequest('Either pointsEarned or prizeAmount is required');
            }

            $command = new AwardScoreCommand(
                participantId: (int) $params['participantId'],
                bettingGameId: Input::int($body, 'bettingGameId'),
                eventId: Input::int($body, 'eventId'),
                pointsEarned: $pointsEarned,
                prizeAmount: $prizeAmount,
                reason: Input::optionalString($body, 'reason'),
                correlationId: $request->header('X-Correlation-ID')
            );
        } catch (InvalidInputException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }

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
