<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Model\Fee;
use BettingGame\Domain\Model\Ticket;
use BettingGame\Domain\Repository\BetRowRepositoryInterface;
use BettingGame\Domain\Repository\FeeRepositoryInterface;
use BettingGame\Domain\Repository\TicketRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Domain\ValueObject\Superzahl;
use DateTimeImmutable;

/**
 * Submitting a ticket is where rows, cost and fees meet.
 *
 * The rows are whatever is valid on the ticket's first day for participants
 * with an active membership - the repository decides that, because both
 * conditions are joins. They are copied onto the ticket as a snapshot, and each
 * participant on it owes the same share of the total.
 */
final class SubmitTicketHandler
{
    public function __construct(
        private TicketRepositoryInterface $tickets,
        private TippYearRepositoryInterface $tippYears,
        private BetRowRepositoryInterface $betRows,
        private FeeRepositoryInterface $fees
    ) {
    }

    public function handle(SubmitTicketCommand $command): CommandResult
    {
        $tippYear = $this->tippYears->find($command->tippYearId);

        if ($tippYear === null) {
            throw new EntityNotFoundException("Tipp year {$command->tippYearId} does not exist");
        }

        if (!$tippYear->acceptsTickets()) {
            throw new BusinessRuleViolationException(
                sprintf('A ticket can only be submitted while the tipp year runs, it is %s', $tippYear->status())
            );
        }

        $periodStart = new DateTimeImmutable($command->periodStart);
        $periodEnd = new DateTimeImmutable($command->periodEnd);

        $rows = $this->betRows->findRowsForTicket($command->tippYearId, $periodStart);

        if ($rows === []) {
            throw new BusinessRuleViolationException(
                'No bet row is valid on ' . $periodStart->format('Y-m-d')
                . ' - check that a bet period covers that day and that members have rows'
            );
        }

        $ticket = Ticket::submit(
            $this->tickets->nextIdentity(),
            $command->tippYearId,
            $periodStart,
            $periodEnd,
            $command->drawCount,
            $tippYear->ticketCostPerRow(),
            array_map(
                static fn ($row): array => [
                    'betRowId' => $row->id(),
                    'participantId' => $row->participantId(),
                    'numbers' => $row->numbers(),
                ],
                $rows
            ),
            $command->superzahl === null ? null : new Superzahl($command->superzahl),
            $command->lotteryReference,
            // The Bearbeitungsentgelt comes from the tipp year's price list and
            // depends on how long this Spielauftrag runs. Read here rather than
            // inside the ticket: the ticket records what it was charged, the
            // year owns the rates.
            $tippYear->processingFees()->forPeriod($periodStart, $periodEnd)
        );

        $this->tickets->save($ticket);

        // The fee is due while the ticket runs, so the end of its period is the
        // last sensible due date.
        //
        // Shares are taken position by position: with the processing fee added
        // the total no longer divides evenly, so one participant carries the
        // odd cent rather than the syndicate quietly under-billing itself.
        $shares = $ticket->feeShares();

        foreach (array_values($ticket->participantIds()) as $index => $participantId) {
            $this->fees->save(Fee::charge(
                $this->fees->nextIdentity(),
                $participantId,
                $ticket->id(),
                $shares[$index],
                $periodEnd
            ));
        }

        return CommandResult::accepted(
            $ticket->id(),
            sprintf('Ticket submitted with %d rows, %.2f total', $ticket->rowCount(), $ticket->totalCost())
        );
    }
}
