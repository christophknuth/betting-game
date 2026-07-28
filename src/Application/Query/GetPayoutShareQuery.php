<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** B-04: the participant's share of a year's winnings. */
final class GetPayoutShareQuery
{
    public function __construct(
        public readonly int $participantId,
        public readonly ?int $tippYearId = null
    ) {
    }
}
