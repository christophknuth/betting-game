<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** OPS-03: the event history of one aggregate. */
final class GetAuditTrailQuery
{
    public function __construct(
        public readonly string $aggregateType,
        public readonly string $aggregateId
    ) {
    }
}
