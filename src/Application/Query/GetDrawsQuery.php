<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** B-05: the draws of a tipp year and what the ticket won in them. */
final class GetDrawsQuery
{
    public function __construct(
        public readonly int $tippYearId,
        public readonly ?string $status = null,
        public readonly bool $withWinningsOnly = false
    ) {
    }
}
