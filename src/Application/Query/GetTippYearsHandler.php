<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Support\Row;

final class GetTippYearsHandler
{
    public function __construct(
        private TippYearRepositoryInterface $tippYears
    ) {
    }

    public function handle(GetTippYearsQuery $query): QueryResult
    {
        $tippYears = [];

        foreach ($this->tippYears->findAll($query->status) as $row) {
            $tippYears[] = [
                'tippYearId' => Row::int($row, 'tipp_year_id'),
                'name' => Row::string($row, 'name'),
                'startDate' => Row::string($row, 'start_date'),
                'endDate' => Row::string($row, 'end_date'),
                'status' => Row::string($row, 'status'),
                'ticketCostPerRow' => Row::float($row, 'ticket_cost_per_row'),
                'processingFeeSingleWeek' => Row::nullableFloat($row, 'processing_fee_single_week') ?? 0.0,
                'processingFeeMultiWeek' => Row::nullableFloat($row, 'processing_fee_multi_week') ?? 0.0,
                'memberCount' => Row::int($row, 'member_count'),
                'ticketCount' => Row::int($row, 'ticket_count'),
                'drawCount' => Row::int($row, 'draw_count'),
                'totalWinnings' => Row::float($row, 'total_winnings'),
            ];
        }

        return new QueryResult(['tippYears' => $tippYears]);
    }
}
