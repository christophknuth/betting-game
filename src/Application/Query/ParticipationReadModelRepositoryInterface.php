<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

interface ParticipationReadModelRepositoryInterface
{
    /**
     * @return ParticipationReadModel[]
     */
    public function findByParticipant(int $participantId, ?string $status = null): array;
}
