<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Repository\TicketRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Support\Row;

/**
 * Shows every ticket of a year and whether the participant's row was on it.
 *
 * The tickets a member missed are the point of this view: joining mid-year is
 * normal, and only listing the tickets they were on would hide it.
 */
final class GetMembershipsHandler
{
    public function __construct(
        private TippYearRepositoryInterface $tippYears,
        private TicketRepositoryInterface $tickets
    ) {
    }

    public function handle(GetMembershipsQuery $query): QueryResult
    {
        $memberships = [];

        foreach ($this->tippYears->membershipsOf($query->participantId) as $membership) {
            $tippYearId = Row::int($membership, 'tipp_year_id');

            if ($query->tippYearId !== null && $tippYearId !== $query->tippYearId) {
                continue;
            }

            $memberships[] = [
                'membershipId' => Row::int($membership, 'membership_id'),
                'participantId' => $query->participantId,
                'tippYearId' => $tippYearId,
                'tippYearName' => Row::string($membership, 'tipp_year_name'),
                'status' => Row::string($membership, 'status'),
                'joinedAt' => Row::string($membership, 'joined_at'),
                'leftAt' => Row::nullableString($membership, 'left_at'),
                'tickets' => $this->ticketsOf($tippYearId, $query->participantId),
            ];
        }

        return new QueryResult(['memberships' => $memberships]);
    }

    /** @return list<array<string, mixed>> */
    private function ticketsOf(int $tippYearId, int $participantId): array
    {
        $tickets = [];

        foreach ($this->tickets->findWithParticipation($tippYearId, $participantId) as $ticket) {
            $tickets[] = [
                'ticketId' => Row::int($ticket, 'ticket_id'),
                'periodStart' => Row::string($ticket, 'period_start'),
                'periodEnd' => Row::string($ticket, 'period_end'),
                'status' => Row::string($ticket, 'status'),
                // Null on the tickets from before the Laufzeit was recorded -
                // the draw count is what they were billed on either way.
                'durationWeeks' => Row::nullableInt($ticket, 'duration_weeks'),
                'drawDays' => Row::nullableString($ticket, 'draw_days'),
                'drawCount' => Row::int($ticket, 'draw_count'),
                'ownRowIncluded' => Row::bool($ticket, 'participated'),
            ];
        }

        return $tickets;
    }
}
