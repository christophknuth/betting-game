<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetAllPredictionsQuery
{
    public function __construct(
        public readonly ?int $bettingGameId = null,
        public readonly ?int $eventId = null,
        public readonly ?int $participantId = null,
        public readonly int $page = 1,
        public readonly int $pageSize = 50
    ) {
    }
}
