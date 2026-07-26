<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

interface LeaderboardReadModelRepositoryInterface
{
    public function getLeaderboard(int $bettingGameId, int $limit = 50): ?LeaderboardReadModel;
}
