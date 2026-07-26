<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetParticipantScoresQuery
{
    public function __construct(
        public readonly int $participantId,
        public readonly ?int $bettingGameId = null
    ) {
    }
}
