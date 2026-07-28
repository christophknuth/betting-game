<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Repository\FeeRepositoryInterface;
use BettingGame\Support\Row;

final class GetParticipantFeesHandler
{
    public function __construct(
        private FeeRepositoryInterface $fees
    ) {
    }

    public function handle(GetParticipantFeesQuery $query): QueryResult
    {
        $rows = $this->fees->findFiltered(
            $query->tippYearId,
            $query->participantId,
            $query->paymentStatus
        );

        $fees = [];
        $totalCharged = 0.0;
        $totalOpen = 0.0;
        $openCount = 0;

        foreach ($rows as $row) {
            $amount = Row::float($row, 'amount');
            $status = Row::string($row, 'payment_status');

            $totalCharged += $amount;

            if ($status === 'open') {
                $totalOpen += $amount;
                $openCount++;
            }

            $fees[] = [
                'feeId' => Row::int($row, 'fee_id'),
                'participantId' => Row::int($row, 'participant_id'),
                'ticketId' => Row::int($row, 'ticket_id'),
                'tippYearId' => Row::int($row, 'tipp_year_id'),
                'periodStart' => Row::string($row, 'period_start'),
                'periodEnd' => Row::string($row, 'period_end'),
                'amount' => $amount,
                'dueDate' => Row::string($row, 'due_date'),
                'paymentStatus' => $status,
                'paidAt' => Row::nullableString($row, 'paid_at'),
                'paymentMethod' => Row::nullableString($row, 'payment_method'),
            ];
        }

        return new QueryResult([
            'fees' => $fees,
            // The summary follows the same filter as the list, so a request
            // for open fees reports the open total, not the year's total.
            'summary' => [
                'totalCharged' => round($totalCharged, 2),
                'totalOpen' => round($totalOpen, 2),
                'openCount' => $openCount,
            ],
        ]);
    }
}
