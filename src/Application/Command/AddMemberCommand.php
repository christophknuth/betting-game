<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-11: take a participant into a tipp year. */
final class AddMemberCommand
{
    public function __construct(
        public readonly int $tippYearId,
        public readonly int $participantId,
        public readonly ?string $joinedAt = null
    ) {
    }
}
