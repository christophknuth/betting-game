<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Repository\FeeRepositoryInterface;
use BettingGame\Support\Row;

/**
 * Same rows as B-03, but across participants and with the name attached -
 * chasing an open fee is pointless without knowing whose it is.
 */
final class GetFeesHandler
{
    public function __construct(
        private FeeRepositoryInterface $fees
    ) {
    }

    public function handle(GetFeesQuery $query): QueryResult
    {
        $fees = [];

        foreach (
            $this->fees->findFiltered(
                $query->tippYearId,
                $query->participantId,
                $query->paymentStatus
            ) as $row
        ) {
            $fees[] = [
                'feeId' => Row::int($row, 'fee_id'),
                'participantId' => Row::int($row, 'participant_id'),
                'displayName' => Row::string($row, 'display_name'),
                'ticketId' => Row::int($row, 'ticket_id'),
                'tippYearId' => Row::int($row, 'tipp_year_id'),
                'periodStart' => Row::string($row, 'period_start'),
                'periodEnd' => Row::string($row, 'period_end'),
                'amount' => Row::float($row, 'amount'),
                'dueDate' => Row::string($row, 'due_date'),
                'paymentStatus' => Row::string($row, 'payment_status'),
                'paidAt' => Row::nullableString($row, 'paid_at'),
                'paymentMethod' => Row::nullableString($row, 'payment_method'),
                'bookedBy' => Row::nullableString($row, 'booked_by'),
            ];
        }

        return new QueryResult(['fees' => $fees]);
    }
}
