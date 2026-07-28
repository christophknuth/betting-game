<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

interface ProjectionStateRepositoryInterface
{
    /**
     * @return array{status: string, lastProcessedPosition: int, updatedAt: string|null, error: string|null}|null
     */
    public function find(string $name): ?array;

    public function markRebuilding(string $name): void;

    public function markRunning(string $name, int $position): void;

    public function markFailed(string $name, string $error): void;
}
