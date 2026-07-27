<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\CreateBettingGameCommand;
use BettingGame\Application\Command\CreateBettingGameHandler;
use BettingGame\Application\Command\EndGameCommand;
use BettingGame\Application\Command\EndGameHandler;
use BettingGame\Application\Query\GetAllGamesQuery;
use BettingGame\Application\Query\GetAllGamesHandler;
use BettingGame\Domain\Exception\DomainException;
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\InvalidInputException;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

final class AdminGameController
{
    public function __construct(
        private CreateBettingGameHandler $createGameHandler,
        private EndGameHandler $endGameHandler,
        private GetAllGamesHandler $getAllGamesHandler
    ) {
    }

    /** @param array<string, string> $params */
    public function getAllGames(Request $request, array $params): JsonResponse
    {
        $gameTypeId = $request->queryParam('gameTypeId');

        $query = new GetAllGamesQuery(
            status: $request->queryParam('status'),
            gameTypeId: $gameTypeId !== null ? (int) $gameTypeId : null
        );

        try {
            $result = $this->getAllGamesHandler->handle($query);
            return JsonResponse::ok($result->data());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    /** @param array<string, string> $params */
    public function createGame(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        try {
            $command = new CreateBettingGameCommand(
                name: Input::string($body, 'name'),
                description: Input::string($body, 'description'),
                gameTypeId: Input::int($body, 'gameTypeId'),
                startDate: Input::string($body, 'startDate'),
                endDate: Input::string($body, 'endDate'),
                baseFee: Input::optionalFloat($body, 'baseFee'),
                feePeriodDays: Input::optionalInt($body, 'feePeriodDays'),
                pointConfiguration: Input::optionalArray($body, 'pointConfiguration'),
                prizeDistribution: Input::optionalArray($body, 'prizeDistribution'),
                correlationId: $request->header('X-Correlation-ID')
            );
        } catch (InvalidInputException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }

        try {
            $result = $this->createGameHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    /** @param array<string, string> $params */
    public function endGame(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        try {
            $command = new EndGameCommand(
                bettingGameId: (int) $params['bettingGameId'],
                reason: Input::string($body, 'reason'),
                finalizeScores: Input::bool($body, 'finalizeScores', true),
                correlationId: $request->header('X-Correlation-ID')
            );
        } catch (InvalidInputException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }

        try {
            $result = $this->endGameHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }
}
