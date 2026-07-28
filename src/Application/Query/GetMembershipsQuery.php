<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** B-02: the participant's memberships and the tickets their row was on. */
final class GetMembershipsQuery
{
    public function __construct(
        public readonly int $participantId,
        public readonly ?int $tippYearId = null
    ) {
    }
}
