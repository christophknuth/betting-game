<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** B-03: the participant's own fees. */
final class GetParticipantFeesQuery
{
    public function __construct(
        public readonly int $participantId,
        public readonly ?int $tippYearId = null,
        public readonly ?string $paymentStatus = null
    ) {
    }
}
