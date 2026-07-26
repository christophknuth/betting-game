<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetLeaderboardQuery
{
    public function __construct(
        public readonly int $bettingGameId,
        public readonly int $limit = 50
    ) {
    }
}
