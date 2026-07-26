<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetParticipationsQuery
{
    public function __construct(
        public readonly int $participantId,
        public readonly ?string $status = null
    ) {
    }
}
