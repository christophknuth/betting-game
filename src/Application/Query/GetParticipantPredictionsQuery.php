<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetParticipantPredictionsQuery
{
    public function __construct(
        public readonly int $participantId,
        public readonly ?int $bettingGameId = null,
        public readonly ?int $eventId = null,
        public readonly ?string $status = null
    ) {
    }
}
