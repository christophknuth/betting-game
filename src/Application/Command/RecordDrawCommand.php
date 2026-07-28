<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-08: record a draw with its six numbers and the Superzahl. */
final class RecordDrawCommand
{
    /**
     * @param list<int> $numbers the six drawn numbers
     */
    public function __construct(
        public readonly int $tippYearId,
        public readonly string $drawDate,
        public readonly array $numbers,
        public readonly int $superzahl
    ) {
    }
}
