<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Event\DomainEvent;

interface EventStoreInterface
{
    /**
     * @param list<DomainEvent> $events
     */
    public function append(string $streamId, array $events, int $expectedVersion): void;

    /**
     * @return list<DomainEvent>
     */
    public function getStream(string $streamId): array;

    public function getStreamVersion(string $streamId): int;
}
