<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-10: create a tipp year with a freely chosen range and price per row. */
final class CreateTippYearCommand
{
    public function __construct(
        public readonly string $name,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly float $ticketCostPerRow
    ) {
    }
}
