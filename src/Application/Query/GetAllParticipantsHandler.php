<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetAllParticipantsHandler
{
    public function __construct(
        private ParticipantReadModelRepositoryInterface $participantRepository
    ) {
    }

    public function handle(GetAllParticipantsQuery $query): QueryResult
    {
        $participants = $this->participantRepository->findAll(
            $query->status,
            $query->bettingGameId
        );

        return new QueryResult([
            'participants' => array_map(fn($p) => $p->toArray(), $participants),
        ]);
    }
}
