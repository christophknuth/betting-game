<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class CreateBettingGameCommand
{
    /**
     * @param array<string, mixed>|null $pointConfiguration
     * @param array<string, mixed>|null $prizeDistribution
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly int $gameTypeId,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?float $baseFee = null,
        public readonly ?int $feePeriodDays = null,
        public readonly ?array $pointConfiguration = null,
        public readonly ?array $prizeDistribution = null,
        public readonly ?string $correlationId = null
    ) {
    }
}
