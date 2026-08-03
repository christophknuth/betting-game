<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\Repository\TicketRepositoryInterface;
use BettingGame\Domain\Service\WinningsDistribution;
use BettingGame\Domain\ValueObject\DrawWinnings;
use BettingGame\Domain\ValueObject\WinningStatement;

/**
 * The administrator types what the statement says - and the system works out
 * which rows produced it and what that comes to.
 *
 * Class by class, the statement gives what *one* row of a class was paid. The
 * ticket's own rows decide the rest: how many of them landed in each class, and
 * therefore the total. Nobody multiplies anything by hand, and no figure can be
 * entered that the rows do not support.
 *
 * The evaluation runs against the row snapshots on the ticket, not against the
 * current bet rows: a row corrected after submission did not take part in this
 * draw with its new numbers.
 *
 * Since B-22 the same matches already exist, worked out when the draw was
 * recorded and carrying no amounts. They are recomputed rather than updated in
 * place: the hits are a function of the numbers and cannot have changed, and
 * recomputing is also the path that catches up a draw recorded before its
 * ticket was handed in.
 */
final class RecordDrawWinningsHandler
{
    public function __construct(
        private DrawRepositoryInterface $draws,
        private TicketRepositoryInterface $tickets
    ) {
    }

    public function handle(RecordDrawWinningsCommand $command): CommandResult
    {
        // The shape of the statement is checked before anything is loaded: a
        // total sent alongside the amounts per class is not a fact about this
        // draw, it is a bad request. What it comes to needs the rows and is
        // settled further down.
        $statement = WinningStatement::of($command->totalAmount, $command->winningClasses);

        $draw = $this->draws->find($command->drawId);

        if ($draw === null) {
            throw new EntityNotFoundException("Draw {$command->drawId} does not exist");
        }

        $drawnNumbers = $draw->numbers();

        if ($drawnNumbers === null) {
            throw new BusinessRuleViolationException('The draw has no numbers yet');
        }

        $ticket = $this->tickets->findCovering($draw->tippYearId(), $draw->drawDate());

        if ($ticket === null) {
            throw new BusinessRuleViolationException(
                sprintf('No ticket covers the draw of %s', $draw->drawDate()->format('Y-m-d'))
            );
        }

        $rows = $this->tickets->snapshotRowsOf($ticket->id());

        // The step the amounts per class were missing: how many of this
        // ticket's rows landed in each of them. Only with that does a Quote
        // become an amount.
        $winnings = $statement->settle(WinningsDistribution::rowsPerClass(
            $drawnNumbers,
            $draw->superzahl(),
            $ticket->superzahl(),
            $rows
        ));

        $matches = WinningsDistribution::of(
            $drawnNumbers,
            $draw->superzahl(),
            $ticket->superzahl(),
            $rows,
            $winnings->total(),
            $winnings->breakdown()
        );

        $draw->recordWinnings($ticket->id(), $winnings->total(), $this->classSummary($winnings));
        $this->draws->save($draw);
        $this->draws->saveRowMatches($draw->id(), $matches);

        return CommandResult::accepted(
            $draw->id(),
            sprintf('Winnings of %.2f recorded for ticket %d', $winnings->total(), $ticket->id())
        );
    }

    /**
     * The classes as they go into the event, so a rebuild can reproduce the
     * same attribution.
     *
     * What was typed (`amount_per_row`), what it applied to (`row_count`) and
     * what came of it (`amount`) are all three recorded. The attribution only
     * needs the last one - the other two are what makes the entry readable next
     * to the statement it came from, years later.
     *
     * @return list<array<string, mixed>>
     */
    private function classSummary(DrawWinnings $winnings): array
    {
        $summary = [];

        foreach ($winnings->classes() as $class) {
            $summary[] = [
                'winning_class' => $class['winningClass'],
                'amount_per_row' => $class['amountPerRow'],
                'row_count' => $class['rowCount'],
                'amount' => $class['amount'],
            ];
        }

        return $summary;
    }
}
