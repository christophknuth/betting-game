<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Exception\EntityNotFoundException;

final class GetLeaderboardHandler
{
    public function __construct(
        private LeaderboardReadModelRepositoryInterface $leaderboardRepository
    ) {
    }

    public function handle(GetLeaderboardQuery $query): QueryResult
    {
        $leaderboard = $this->leaderboardRepository->getLeaderboard(
            $query->bettingGameId,
            $query->limit
        );

        if ($leaderboard === null) {
            throw new EntityNotFoundException('Leaderboard not found for this game');
        }

        return new QueryResult($leaderboard->toArray());
    }
}
