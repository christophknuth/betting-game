<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class LeaderboardReadModel
{
    /** @param list<array<string, mixed>> $rankings */
    public function __construct(
        public readonly int $bettingGameId,
        public readonly string $bettingGameName,
        public readonly array $rankings,
        public readonly string $updatedAt
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'bettingGameId' => $this->bettingGameId,
            'bettingGameName' => $this->bettingGameName,
            'rankings' => $this->rankings,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
