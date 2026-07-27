<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetPendingParticipantsQuery
{
    public function __construct(
        public readonly int $bettingGameId
    ) {
    }
}
