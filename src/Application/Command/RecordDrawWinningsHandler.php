<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\Repository\TicketRepositoryInterface;
use BettingGame\Domain\Service\WinningsDistribution;

/**
 * The administrator reads one number off the statement - what the ticket won -
 * and the system works out which rows produced it.
 *
 * The evaluation runs against the row snapshots on the ticket, not against the
 * current bet rows: a row corrected after submission did not take part in this
 * draw with its new numbers.
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

        $ticketRowIds = $this->tickets->rowIdsOf($ticket->id());
        $rows = [];

        foreach ($ticket->rows() as $row) {
            $ticketRowId = $ticketRowIds[$row['betRowId']] ?? null;

            if ($ticketRowId !== null) {
                $rows[] = ['ticketRowId' => $ticketRowId, 'numbers' => $row['numbers']];
            }
        }

        $matches = WinningsDistribution::of(
            $drawnNumbers,
            $draw->superzahl(),
            $ticket->superzahl(),
            $rows,
            $command->totalAmount,
            $command->winningClasses
        );

        $draw->recordWinnings($ticket->id(), $command->totalAmount, $this->classSummary($command));
        $this->draws->save($draw);
        $this->draws->saveRowMatches($draw->id(), $matches);

        return CommandResult::accepted(
            $draw->id(),
            sprintf('Winnings of %.2f recorded for ticket %d', $command->totalAmount, $ticket->id())
        );
    }

    /**
     * The breakdown as it goes into the event, so a rebuild can reproduce the
     * same attribution.
     *
     * @return list<array<string, mixed>>
     */
    private function classSummary(RecordDrawWinningsCommand $command): array
    {
        $summary = [];

        foreach ($command->winningClasses as $class) {
            $summary[] = [
                'winning_class' => $class['winningClass'],
                'amount' => $class['amount'],
            ];
        }

        return $summary;
    }
}
