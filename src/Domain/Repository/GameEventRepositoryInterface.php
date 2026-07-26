<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

interface GameEventRepositoryInterface
{
    public function findById(int $id): ?array;
    
    public function getDeadline(int $eventId): ?\DateTimeImmutable;
}
