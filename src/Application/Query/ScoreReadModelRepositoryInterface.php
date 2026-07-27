<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

interface ScoreReadModelRepositoryInterface
{
    /**
     * @return list<ParticipantScoreReadModel>
     */
    public function findByParticipant(int $participantId, ?int $bettingGameId = null): array;

    /** @return array<string, mixed> */
    public function getSummary(int $participantId): array;
}
