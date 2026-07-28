<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Model\Draw;
use BettingGame\Domain\Model\Ticket;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\Repository\TicketRepositoryInterface;
use BettingGame\Domain\ValueObject\EvenSplit;

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

        $ticket = $this->tickets->findCovering($draw->tippYearId(), $draw->drawDate());

        if ($ticket === null) {
            throw new BusinessRuleViolationException(
                sprintf('No ticket covers the draw of %s', $draw->drawDate()->format('Y-m-d'))
            );
        }

        $matches = $this->evaluateRows($draw, $ticket, $command);

        $draw->recordWinnings($ticket->id(), $command->totalAmount, $this->classSummary($command));
        $this->draws->save($draw);
        $this->draws->saveRowMatches($draw->id(), $matches);

        return CommandResult::accepted(
            $draw->id(),
            sprintf('Winnings of %.2f recorded for ticket %d', $command->totalAmount, $ticket->id())
        );
    }

    /**
     * @return list<array{ticketRowId: int, matchedNumbers: int, superzahlMatched: bool,
     *     winningClass: int|null, amount: float}>
     */
    private function evaluateRows(Draw $draw, Ticket $ticket, RecordDrawWinningsCommand $command): array
    {
        $ticketRowIds = $this->tickets->rowIdsOf($ticket->id());
        $evaluated = [];

        foreach ($ticket->rows() as $row) {
            $ticketRowId = $ticketRowIds[$row['betRowId']] ?? null;

            if ($ticketRowId === null) {
                continue;
            }

            $result = $draw->evaluate($row['numbers'], $ticket->superzahl());

            $evaluated[] = [
                'ticketRowId' => $ticketRowId,
                'matchedNumbers' => $result['matchedNumbers'],
                'superzahlMatched' => $result['superzahlMatched'],
                'winningClass' => $result['winningClass'],
                'amount' => 0.0,
            ];
        }

        return $this->distribute($evaluated, $command);
    }

    /**
     * Puts money on the rows that won.
     *
     * With an explicit breakdown each class's amount is split among the rows in
     * that class. Without one there is no way to tell classes apart, so the
     * total is split evenly across every winning row - stated plainly because
     * it is an assumption, not a fact from the statement.
     *
     * @param list<array{ticketRowId: int, matchedNumbers: int, superzahlMatched: bool,
     *     winningClass: int|null, amount: float}> $rows
     *
     * @return list<array{ticketRowId: int, matchedNumbers: int, superzahlMatched: bool,
     *     winningClass: int|null, amount: float}>
     */
    private function distribute(array $rows, RecordDrawWinningsCommand $command): array
    {
        $winnersByClass = [];

        foreach ($rows as $index => $row) {
            if ($row['winningClass'] !== null) {
                $winnersByClass[$row['winningClass']][] = $index;
            }
        }

        if ($winnersByClass === []) {
            return $rows;
        }

        /** @var array<int, float> $amounts row index => its share */
        $amounts = [];

        if ($command->winningClasses !== []) {
            foreach ($command->winningClasses as $class) {
                $indexes = $winnersByClass[$class['winningClass']] ?? [];

                if ($indexes === []) {
                    continue;
                }

                foreach (EvenSplit::of($class['amount'], count($indexes)) as $position => $share) {
                    $amounts[$indexes[$position]] = $share;
                }
            }
        } else {
            $allWinners = array_merge(...array_values($winnersByClass));

            foreach (EvenSplit::of($command->totalAmount, count($allWinners)) as $position => $share) {
                $amounts[$allWinners[$position]] = $share;
            }
        }

        $settled = [];
        foreach ($rows as $index => $row) {
            $row['amount'] = $amounts[$index] ?? 0.0;
            $settled[] = $row;
        }

        return $settled;
    }

    /**
     * The breakdown as it goes into the event.
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
