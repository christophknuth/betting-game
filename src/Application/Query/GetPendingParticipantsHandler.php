<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetPendingParticipantsHandler
{
    public function __construct(
        private ParticipantReadModelRepositoryInterface $participantRepository
    ) {
    }

    public function handle(GetPendingParticipantsQuery $query): QueryResult
    {
        $participants = $this->participantRepository->findPendingByGame($query->bettingGameId);

        return new QueryResult([
            'pendingParticipants' => array_map(fn($p) => $p->toArray(), $participants),
        ]);
    }
}
