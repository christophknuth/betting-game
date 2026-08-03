<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

/**
 * B-12: record the shared ticket for one month.
 *
 * What is handed in at the counter is a day, a Laufzeit in weeks and the draw
 * days - so that is what this carries. The period's end and the number of draws
 * are the handler's to derive; letting a caller send them would allow a ticket
 * whose cost does not match what it plays.
 */
final class SubmitTicketCommand
{
    public function __construct(
        public readonly int $tippYearId,
        public readonly string $periodStart,
        public readonly int $durationWeeks,
        public readonly string $drawDays,
        public readonly ?int $superzahl = null,
        public readonly ?string $lotteryReference = null
    ) {
    }
}
