<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\ValueObject\ParticipantStatus;
use BettingGame\Support\Row;

/**
 * B-21: the participant list behind every "which participant?" field.
 *
 * Admin-only, and deliberately not a participant-facing route: a member has no
 * business enumerating the others (B-16). It exists so the administrator can
 * pick a name instead of typing an id - the reason the admin views used to ask
 * for a raw participant ID.
 */
final class GetParticipantsHandler
{
    public function __construct(
        private ParticipantRepositoryInterface $participants
    ) {
    }

    public function handle(GetParticipantsQuery $query): QueryResult
    {
        $participants = [];

        // Constructed for the check alone: an unknown filter is a bad request,
        // and answering it with an empty roster would look like a syndicate
        // that has no members.
        $filter = $query->status === null ? null : (new ParticipantStatus($query->status))->value();

        foreach ($this->participants->findAll($filter) as $row) {
            $status = Row::string($row, 'status');

            $participants[] = [
                'participantId' => Row::int($row, 'participant_id'),
                'displayName' => Row::string($row, 'display_name'),
                'status' => $status,
                // Three states, one of which is the interesting one for
                // everything that asks "may this person play?"
                'isActive' => $status === ParticipantStatus::ACTIVE,
                // E1-01: whether they signed themselves up. Not the subject
                // itself - that identifies an account and belongs in no list.
                'selfRegistered' => Row::nullableString($row, 'keycloak_subject') !== null,
                'registeredAt' => Row::string($row, 'registered_at'),
            ];
        }

        return new QueryResult(['participants' => $participants]);
    }
}
