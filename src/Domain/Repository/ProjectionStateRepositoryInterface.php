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

    /**
     * Records how far the read model is current, without judging its health.
     *
     * The write path calls this: a repository writes its projection in the same
     * transaction as the events, so by the time it commits, the read model is
     * current up to that position. Without it the counter only ever moved on a
     * rebuild, and OPS-04 reported a lag that grew with every command while the
     * data was in fact up to date - a monitor that always cries wolf teaches
     * the operator to ignore it.
     *
     * Deliberately leaves `status` and `error_message` alone, unlike
     * markRunning(). A projection left `failed` by a botched rebuild is still
     * half-built; that new writes keep landing does not undo it, and clearing
     * the flag here would hide the one thing worth seeing.
     */
    public function advance(string $name, int $position): void;
}
