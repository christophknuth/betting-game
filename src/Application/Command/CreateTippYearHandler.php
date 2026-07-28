<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Model\TippYear;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Domain\ValueObject\DateRange;

final class CreateTippYearHandler
{
    public function __construct(
        private TippYearRepositoryInterface $tippYears
    ) {
    }

    public function handle(CreateTippYearCommand $command): CommandResult
    {
        $range = DateRange::fromStrings($command->startDate, $command->endDate);

        TippYear::assertNoOverlap($range, $this->tippYears->existingRanges());

        $tippYear = TippYear::create(
            $this->tippYears->nextIdentity(),
            $command->name,
            $range->start(),
            $range->end(),
            $command->ticketCostPerRow
        );

        $this->tippYears->save($tippYear);

        return CommandResult::accepted($tippYear->id(), 'Tipp year created');
    }
}
