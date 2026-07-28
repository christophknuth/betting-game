<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\BetPeriodRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;

final class GetBetPeriodsHandler
{
    public function __construct(
        private BetPeriodRepositoryInterface $betPeriods,
        private TippYearRepositoryInterface $tippYears
    ) {
    }

    public function handle(GetBetPeriodsQuery $query): QueryResult
    {
        $tippYear = $this->tippYears->find($query->tippYearId);

        if ($tippYear === null) {
            throw new EntityNotFoundException("Tipp year {$query->tippYearId} does not exist");
        }

        $counts = $this->betPeriods->betRowCounts($query->tippYearId);
        $betPeriods = [];

        foreach ($this->betPeriods->findByTippYear($query->tippYearId) as $period) {
            $betPeriods[] = [
                'betPeriodId' => $period->id(),
                'tippYearId' => $period->tippYearId(),
                'tippYearName' => $tippYear->name(),
                'name' => $period->name(),
                'startDate' => $period->range()->start()->format('Y-m-d'),
                'endDate' => $period->range()->end()->format('Y-m-d'),
                'sequence' => $period->sequence(),
                'betRowCount' => $counts[$period->id()] ?? 0,
            ];
        }

        return new QueryResult(['betPeriods' => $betPeriods]);
    }
}
