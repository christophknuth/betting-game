<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-14: define a validity range for bet rows inside a tipp year. */
final class CreateBetPeriodCommand
{
    public function __construct(
        public readonly int $tippYearId,
        public readonly string $name,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?int $sequence = null
    ) {
    }
}
