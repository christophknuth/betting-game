<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

/**
 * The record of every command the API accepted.
 *
 * Serves two operational needs at once: OPS-01 wants to know what became of a
 * command, and OPS-02 wants a repeated command to be recognised rather than
 * executed twice.
 */
interface CommandLogRepositoryInterface
{
    /**
     * Claims an idempotency key before the command runs.
     *
     * Returns false when the key is already taken - the caller then replays the
     * existing entry instead of executing. Claiming has to happen *before*
     * execution, otherwise two concurrent retries both do the work and only the
     * bookkeeping is deduplicated.
     */
    public function claim(string $commandId, string $commandType, ?string $idempotencyKey): bool;

    public function markCompleted(string $commandId, int $status, string $responseBody, ?int $resourceId): void;

    public function markFailed(string $commandId, int $status, string $error): void;

    /** @return array<string, mixed>|null */
    public function find(string $commandId): ?array;

    /** @return array<string, mixed>|null */
    public function findByIdempotencyKey(string $idempotencyKey): ?array;
}
