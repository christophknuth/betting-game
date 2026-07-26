<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

interface EventStoreInterface
{
    public function append(string $streamId, array $events, int $expectedVersion): void;
    
    /**
     * @return \BettingGame\Domain\Event\DomainEvent[]
     */
    public function getStream(string $streamId): array;
    
    public function getStreamVersion(string $streamId): int;
}
