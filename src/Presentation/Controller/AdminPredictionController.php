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

    /** @param array<string, string> $params */
    public function getAllPredictions(Request $request, array $params): JsonResponse
    {
        $bettingGameId = $request->queryParam('bettingGameId');
        $eventId = $request->queryParam('eventId');
        $participantId = $request->queryParam('participantId');
        $page = $request->queryParam('page');
        $pageSize = $request->queryParam('pageSize');

        $query = new GetAllPredictionsQuery(
            bettingGameId: $bettingGameId !== null ? (int) $bettingGameId : null,
            eventId: $eventId !== null ? (int) $eventId : null,
            participantId: $participantId !== null ? (int) $participantId : null,
            page: $page !== null ? (int) $page : 1,
            pageSize: $pageSize !== null ? (int) $pageSize : 50
        );

        try {
            $result = $this->getAllPredictionsHandler->handle($query);
            return JsonResponse::ok($result->data());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }
}
