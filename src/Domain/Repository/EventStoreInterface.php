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

    /**
     * OPS-03: an aggregate's history, oldest first, with its versions.
     *
     * @return list<RecordedEvent>
     */
    public function recordsOf(string $streamId): array;

    /**
     * OPS-04: the global event log from a position onwards, for replaying a
     * projection. Exclusive in `$afterPosition`, so a projection can hand back
     * the position it last processed.
     *
     * @param list<string> $eventTypes restricts the read to these types; empty reads all
     *
     * @return list<RecordedEvent>
     */
    public function readFrom(int $afterPosition, int $limit = 1000, array $eventTypes = []): array;

    /**
     * The position of the newest event, or 0 when the store is empty.
     */
    public function headPosition(): int;

    /**
     * How many events of the given types sit after a position - a projection's
     * lag, without reading the events themselves.
     *
     * @param list<string> $eventTypes empty counts all types
     */
    public function countFrom(int $afterPosition, array $eventTypes = []): int;
}
