<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetAllParticipantsQuery
{
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?int $bettingGameId = null
    ) {
    }
}
