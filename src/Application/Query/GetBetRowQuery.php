<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** B-01: the participant's own bet row. */
final class GetBetRowQuery
{
    public function __construct(
        public readonly int $participantId,
        public readonly ?int $betPeriodId = null
    ) {
    }
}
