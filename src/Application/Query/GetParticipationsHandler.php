<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetParticipationsHandler
{
    public function __construct(
        private ParticipationReadModelRepositoryInterface $participationRepository
    ) {
    }

    public function handle(GetParticipationsQuery $query): QueryResult
    {
        $participations = $this->participationRepository->findByParticipant(
            $query->participantId,
            $query->status
        );

        return new QueryResult([
            'participations' => array_map(fn($p) => $p->toArray(), $participations),
        ]);
    }
}
