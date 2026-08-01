<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/**
 * B-10: create a tipp year with a freely chosen range and its price list.
 *
 * The price list is two-part, the way the lottery company charges: a price per
 * row and draw, plus a Bearbeitungsentgelt per Spielauftrag whose rate depends
 * on whether the order runs one week or longer. Both fees default to zero, so
 * a syndicate that is not charged one can ignore them.
 */
final class CreateTippYearCommand
{
    public function __construct(
        public readonly string $name,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly float $ticketCostPerRow,
        public readonly float $processingFeeSingleWeek = 0.0,
        public readonly float $processingFeeMultiWeek = 0.0
    ) {
    }
}
