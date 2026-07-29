<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-18: move a tipp year to another status. */
final class ChangeTippYearStatusCommand
{
    public function __construct(
        public readonly int $tippYearId,
        public readonly string $status
    ) {
    }
}
