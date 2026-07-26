<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Query\GetAllPredictionsQuery;
use BettingGame\Application\Query\GetAllPredictionsHandler;
use BettingGame\Domain\Exception\DomainException;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

final class AdminPredictionController
{
    public function __construct(
        private GetAllPredictionsHandler $getAllPredictionsHandler
    ) {
    }

    public function getAllPredictions(Request $request, array $params): JsonResponse
    {
        $query = new GetAllPredictionsQuery(
            bettingGameId: $request->queryParam('bettingGameId') ? (int) $request->queryParam('bettingGameId') : null,
            eventId: $request->queryParam('eventId') ? (int) $request->queryParam('eventId') : null,
            participantId: $request->queryParam('participantId') ? (int) $request->queryParam('participantId') : null,
            page: $request->queryParam('page') ? (int) $request->queryParam('page') : 1,
            pageSize: $request->queryParam('pageSize') ? (int) $request->queryParam('pageSize') : 50
        );

        try {
            $result = $this->getAllPredictionsHandler->handle($query);
            return JsonResponse::ok($result->data());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }
}
