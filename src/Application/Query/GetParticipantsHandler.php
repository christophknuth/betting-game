<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
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

        foreach ($this->participants->findAll($query->isActive) as $row) {
            $participants[] = [
                'participantId' => Row::int($row, 'participant_id'),
                'displayName' => Row::string($row, 'display_name'),
                'isActive' => Row::bool($row, 'is_active'),
                'registeredAt' => Row::string($row, 'registered_at'),
            ];
        }

        return new QueryResult(['participants' => $participants]);
    }
}
