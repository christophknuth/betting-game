<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-12: record the shared ticket for one month. */
final class SubmitTicketCommand
{
    public function __construct(
        public readonly int $tippYearId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly int $drawCount,
        public readonly ?int $superzahl = null,
        public readonly ?string $lotteryReference = null
    ) {
    }
}
