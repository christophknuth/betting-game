<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class GetParticipantScoresHandler
{
    public function __construct(
        private ScoreReadModelRepositoryInterface $scoreRepository
    ) {
    }

    public function handle(GetParticipantScoresQuery $query): QueryResult
    {
        $scores = $this->scoreRepository->findByParticipant(
            $query->participantId,
            $query->bettingGameId
        );

        $summary = $this->scoreRepository->getSummary($query->participantId);

        return new QueryResult([
            'scores' => array_map(fn($s) => $s->toArray(), $scores),
            'summary' => $summary,
        ]);
    }
}
