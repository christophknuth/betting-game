<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** The administrator's view of the whole fee ledger. */
final class GetFeesQuery
{
    public function __construct(
        public readonly ?int $tippYearId = null,
        public readonly ?int $participantId = null,
        public readonly ?string $paymentStatus = null
    ) {
    }
}
