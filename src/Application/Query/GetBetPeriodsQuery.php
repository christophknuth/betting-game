<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** The bet periods of a tipp year - the configured rhythm of row changes. */
final class GetBetPeriodsQuery
{
    public function __construct(
        public readonly int $tippYearId
    ) {
    }
}
