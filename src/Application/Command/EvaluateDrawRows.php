<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Model\Draw;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\Repository\TicketRepositoryInterface;
use BettingGame\Domain\Service\WinningsDistribution;

/**
 * B-22: works out what every row of the covering ticket hit in a draw.
 *
 * Two commands need this and need the same answer from it - recording a draw
 * and correcting one. It sits in its own class rather than in both of them
 * because the alternative is two implementations of "which rows, against which
 * Superzahl", and those drift into two different sets of winning classes.
 *
 * The amounts stay at zero: what the ticket won is not known here, and a guess
 * would be indistinguishable from a booking. B-09 recomputes the same matches
 * with the money in hand.
 */
final class EvaluateDrawRows
{
    public function __construct(
        private DrawRepositoryInterface $draws,
        private TicketRepositoryInterface $tickets
    ) {
    }

    /**
     * Returns what happened, in the words the command answers with.
     *
     * A draw whose ticket has not been handed in yet has nothing to evaluate
     * against. That is not an error: the draw is recorded, and B-09 catches the
     * evaluation up when the winnings arrive.
     */
    public function of(Draw $draw): string
    {
        // Whatever was worked out before belongs to the numbers as they were,
        // and after a correction to possibly another ticket altogether. Rows
        // that fall away have to go rather than be left behind.
        $this->draws->clearRowMatches($draw->id());

        $numbers = $draw->numbers();
        $ticket = $this->tickets->findCovering($draw->tippYearId(), $draw->drawDate());

        if ($numbers === null || $ticket === null) {
            return 'no ticket covers it yet';
        }

        $rows = $this->tickets->snapshotRowsOf($ticket->id());

        if ($rows === []) {
            return 'the covering ticket has no rows';
        }

        $this->draws->saveRowMatches($draw->id(), WinningsDistribution::of(
            $numbers,
            $draw->superzahl(),
            $ticket->superzahl(),
            $rows,
            0.0
        ));

        return sprintf('%d rows of ticket %d evaluated', count($rows), $ticket->id());
    }
}
