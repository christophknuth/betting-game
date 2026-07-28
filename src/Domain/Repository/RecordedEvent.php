<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Event\DomainEvent;

/**
 * An event together with where it sits in the store.
 *
 * A DomainEvent on its own carries no position: it does not know whether it is
 * the third event of its stream or the ten-thousandth overall. Both numbers are
 * what operations needs - the version for an aggregate's history, the global
 * position for telling a projection how far it has caught up.
 */
final class RecordedEvent
{
    public function __construct(
        public readonly int $position,
        public readonly int $version,
        public readonly DomainEvent $event
    ) {
    }
}
