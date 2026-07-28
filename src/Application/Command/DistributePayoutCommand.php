<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/** B-13: book the annual distribution. */
final class DistributePayoutCommand
{
    public function __construct(
        public readonly int $tippYearId,
        public readonly bool $confirm,
        public readonly ?string $note = null,
        public readonly ?string $bookedBy = null
    ) {
    }
}
