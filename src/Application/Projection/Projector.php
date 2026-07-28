<?php

declare(strict_types=1);

namespace BettingGame\Application\Projection;

use BettingGame\Domain\Repository\RecordedEvent;

/**
 * Builds one read model out of the event log.
 *
 * The repositories write their projections synchronously when an aggregate is
 * saved, because a load right afterwards has to see them. A projector is the
 * second way to the same rows: it replays the log, which is what makes OPS-04's
 * rebuild possible after a schema change or a bad deployment.
 *
 * That the two paths agree is not obvious, so it is asserted directly - the
 * rebuild test snapshots every table, rebuilds, and compares.
 */
interface Projector
{
    /**
     * The name in `projection_state`.
     */
    public function name(): string;

    /**
     * Event types this projector consumes. Used both to filter the replay and
     * to work out how far behind the projection is.
     *
     * @return list<string>
     */
    public function eventTypes(): array;

    /**
     * Empties the tables this projector owns, ready for a replay.
     */
    public function reset(): void;

    public function apply(RecordedEvent $record): void;
}
