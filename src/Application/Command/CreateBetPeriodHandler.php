<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Model\BetPeriod;
use BettingGame\Domain\Repository\BetPeriodRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Domain\ValueObject\DateRange;

/**
 * Two rules guard a new period, and only one of them can live in the schema.
 *
 * "Inside the tipp year" and "does not overlap a sibling" are both checked
 * here; the unique key on (tipp_year_id, start_date) then catches the narrow
 * case of two periods starting on the same day, including the race between two
 * concurrent requests that both passed the overlap check.
 */
final class CreateBetPeriodHandler
{
    public function __construct(
        private BetPeriodRepositoryInterface $betPeriods,
        private TippYearRepositoryInterface $tippYears
    ) {
    }

    public function handle(CreateBetPeriodCommand $command): CommandResult
    {
        $tippYear = $this->tippYears->find($command->tippYearId);

        if ($tippYear === null) {
            throw new EntityNotFoundException("Tipp year {$command->tippYearId} does not exist");
        }

        $range = DateRange::fromStrings($command->startDate, $command->endDate);

        BetPeriod::assertNoOverlap($range, $this->betPeriods->existingRanges($command->tippYearId));

        $period = BetPeriod::create(
            $this->betPeriods->nextIdentity(),
            $command->tippYearId,
            $command->name,
            $range,
            $tippYear->range(),
            $command->sequence ?? $this->betPeriods->nextSequence($command->tippYearId)
        );

        $this->betPeriods->save($period);

        return CommandResult::accepted($period->id(), 'Bet period created');
    }
}
