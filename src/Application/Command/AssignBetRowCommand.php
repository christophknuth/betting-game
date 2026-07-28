<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-06: assign a participant's bet row for a bet period. */
final class AssignBetRowCommand
{
    /**
     * @param list<int> $numbers six distinct numbers from 1 to 49
     */
    public function __construct(
        public readonly int $participantId,
        public readonly int $betPeriodId,
        public readonly array $numbers,
        public readonly ?string $replaceReason = null
    ) {
    }
}
