<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Model\Draw;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\Repository\TicketRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Domain\Service\WinningsDistribution;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use DateTimeImmutable;

final class RecordDrawHandler
{
    public function __construct(
        private DrawRepositoryInterface $draws,
        private TippYearRepositoryInterface $tippYears,
        private TicketRepositoryInterface $tickets
    ) {
    }

    public function handle(RecordDrawCommand $command): CommandResult
    {
        $tippYear = $this->tippYears->find($command->tippYearId);

        if ($tippYear === null) {
            throw new EntityNotFoundException("Tipp year {$command->tippYearId} does not exist");
        }

        $drawDate = new DateTimeImmutable($command->drawDate);

        // A draw outside the year would never reach a ticket and would still
        // count towards the year's winnings - reject it rather than orphan it.
        if (!$tippYear->range()->contains($drawDate)) {
            throw new BusinessRuleViolationException(
                sprintf('The draw date %s is outside the tipp year %s', $drawDate->format('Y-m-d'), $tippYear->range())
            );
        }

        $draw = Draw::record(
            $this->draws->nextIdentity(),
            $command->tippYearId,
            $drawDate,
            new LottoNumbers($command->numbers),
            new Superzahl($command->superzahl)
        );

        // uk_draw_date rejects a duplicate date as a DuplicateEntryException
        $this->draws->save($draw);

        return CommandResult::accepted($draw->id(), $this->evaluateRows($draw));
    }

    /**
     * B-22: the hits per row are known the moment the numbers are, so they are
     * worked out here rather than waiting for the winnings.
     *
     * The amounts stay at zero - what the ticket won is not known yet, and a
     * guess would be indistinguishable from a booking. B-09 recomputes the same
     * matches with the money in hand and overwrites them.
     *
     * A draw whose ticket has not been handed in yet has nothing to evaluate
     * against. That is not an error: the draw is recorded, and B-09 catches the
     * evaluation up later.
     */
    private function evaluateRows(Draw $draw): string
    {
        $numbers = $draw->numbers();
        $ticket = $this->tickets->findCovering($draw->tippYearId(), $draw->drawDate());

        if ($numbers === null || $ticket === null) {
            return 'Draw recorded, no ticket covers it yet';
        }

        $rows = $this->tickets->snapshotRowsOf($ticket->id());

        if ($rows === []) {
            return 'Draw recorded, the covering ticket has no rows';
        }

        $this->draws->saveRowMatches($draw->id(), WinningsDistribution::of(
            $numbers,
            $draw->superzahl(),
            $ticket->superzahl(),
            $rows,
            0.0
        ));

        return sprintf('Draw recorded, %d rows of ticket %d evaluated', count($rows), $ticket->id());
    }
}
