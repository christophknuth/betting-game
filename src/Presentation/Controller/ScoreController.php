<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Query\GetLeaderboardQuery;
use BettingGame\Application\Query\GetLeaderboardHandler;
use BettingGame\Application\Query\GetParticipantScoresQuery;
use BettingGame\Application\Query\GetParticipantScoresHandler;
use BettingGame\Domain\Exception\DomainException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

final class ScoreController
{
    public function __construct(
        private GetParticipantScoresHandler $scoresHandler,
        private GetLeaderboardHandler $leaderboardHandler
    ) {
    }

    public function getScores(Request $request, array $params): JsonResponse
    {
        $participantId = (int) $params['participantId'];
        
        if (!$this->isAuthorized($request, $participantId)) {
            return JsonResponse::forbidden('Access denied');
        }

        $query = new GetParticipantScoresQuery(
            participantId: $participantId,
            bettingGameId: $request->queryParam('bettingGameId') ? (int) $request->queryParam('bettingGameId') : null
        );

        try {
            $result = $this->scoresHandler->handle($query);
            return JsonResponse::ok($result->data());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    public function getLeaderboard(Request $request, array $params): JsonResponse
    {
        $participantId = (int) $params['participantId'];

        if (!$this->isAuthorized($request, $participantId)) {
            return JsonResponse::forbidden('Access denied');
        }

        $limit = $request->queryParam('limit');

        $query = new GetLeaderboardQuery(
            bettingGameId: (int) $params['bettingGameId'],
            limit: $limit !== null ? (int) $limit : 50
        );

        try {
            $result = $this->leaderboardHandler->handle($query);
            return JsonResponse::ok($result->data());
        } catch (EntityNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    private function isAuthorized(Request $request, int $participantId): bool
    {
        $authParticipantId = $request->attribute('participant_id');
        return $authParticipantId === $participantId;
    }
}
