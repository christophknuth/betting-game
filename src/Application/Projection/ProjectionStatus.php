<?php

declare(strict_types=1);

namespace BettingGame\Application\Projection;

/**
 * Where a projection stands relative to the event log.
 *
 * `lag` is the number that matters in operations: not how many events a
 * projection has seen, but how many it has not seen yet.
 */
final class ProjectionStatus
{
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly int $lastProcessedPosition,
        public readonly int $headPosition,
        public readonly int $lag,
        public readonly ?string $updatedAt,
        public readonly ?string $error
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'lastProcessedPosition' => $this->lastProcessedPosition,
            'headPosition' => $this->headPosition,
            'lag' => $this->lag,
            'upToDate' => $this->lag === 0,
            'updatedAt' => $this->updatedAt,
            'error' => $this->error,
        ];
    }
}
