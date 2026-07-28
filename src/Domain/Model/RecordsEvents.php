<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\DomainEvent;

/**
 * The event bookkeeping every aggregate here does identically.
 *
 * Two version numbers, and the difference matters: `version` is where the
 * aggregate is now, `originalVersion` is the stream version it was loaded at
 * and therefore the version an append must expect. They only differ between
 * loading and saving - that window is what optimistic locking guards.
 */
trait RecordsEvents
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];
    private int $version = 0;
    private int $originalVersion = 0;
    private bool $persisted = false;

    private function recordEvent(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * Stream version this instance was loaded at - the expected version when appending.
     */
    public function originalVersion(): int
    {
        return $this->originalVersion;
    }

    /**
     * Moves the append reference forward after the repository has written.
     *
     * Without this an instance is stale the moment it saves itself: the stream
     * has advanced but the object still expects the version it loaded at, so a
     * second save of the same instance fails the optimistic-locking check even
     * though nobody else touched it.
     */
    public function markCommitted(int $version): void
    {
        $this->version = $version;
        $this->originalVersion = $version;
        $this->persisted = true;
    }

    /**
     * Whether a row for this aggregate already exists.
     *
     * The repository needs this to pick INSERT over UPDATE, and the difference
     * is not cosmetic: an upsert would silently overwrite a *different*
     * aggregate that happens to collide on a business unique key - a second bet
     * row for the same participant and period would land on top of the first
     * instead of being rejected.
     */
    public function isPersisted(): bool
    {
        return $this->persisted;
    }
}
