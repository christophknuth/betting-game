<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

/** Admin overview of the tipp years. */
final class GetTippYearsQuery
{
    public function __construct(
        public readonly ?string $status = null
    ) {
    }
}
