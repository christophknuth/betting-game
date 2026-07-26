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

    public function getAllGames(Request $request, array $params): JsonResponse
    {
        $query = new GetAllGamesQuery(
            status: $request->queryParam('status'),
            gameTypeId: $request->queryParam('gameTypeId') ? (int) $request->queryParam('gameTypeId') : null
        );

        try {
            $result = $this->getAllGamesHandler->handle($query);
            return JsonResponse::ok($result->data());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    public function createGame(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        if (!isset($body['name'], $body['description'], $body['gameTypeId'], $body['startDate'], $body['endDate'])) {
            return JsonResponse::badRequest('name, description, gameTypeId, startDate, and endDate are required');
        }

        $command = new CreateBettingGameCommand(
            name: $body['name'],
            description: $body['description'],
            gameTypeId: (int) $body['gameTypeId'],
            startDate: $body['startDate'],
            endDate: $body['endDate'],
            baseFee: isset($body['baseFee']) ? (float) $body['baseFee'] : null,
            feePeriodDays: isset($body['feePeriodDays']) ? (int) $body['feePeriodDays'] : null,
            pointConfiguration: $body['pointConfiguration'] ?? null,
            prizeDistribution: $body['prizeDistribution'] ?? null,
            correlationId: $request->header('X-Correlation-ID')
        );

        try {
            $result = $this->createGameHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    public function endGame(Request $request, array $params): JsonResponse
    {
        $bettingGameId = (int) $params['bettingGameId'];
        $body = $request->jsonBody();

        if (!isset($body['reason'])) {
            return JsonResponse::badRequest('reason is required');
        }

        $command = new EndGameCommand(
            bettingGameId: $bettingGameId,
            reason: $body['reason'],
            finalizeScores: $body['finalizeScores'] ?? true,
            correlationId: $request->header('X-Correlation-ID')
        );

        try {
            $result = $this->endGameHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }
}
