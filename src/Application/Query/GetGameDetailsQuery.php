<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetGameDetailsQuery
{
    public function __construct(
        public readonly int $bettingGameId
    ) {
    }
}
