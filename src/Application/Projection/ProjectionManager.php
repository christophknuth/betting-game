<?php

declare(strict_types=1);

namespace BettingGame\Application\Projection;

use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Repository\ProjectionStateRepositoryInterface;
use Throwable;

/**
 * OPS-04: watching the projections and rebuilding them.
 *
 * The repositories keep their read models current as they write, so under
 * normal operation nothing here has to run. It exists for the two situations
 * where that is not enough: a projection that was changed and has to be filled
 * from history, and one that is suspected of being wrong.
 */
final class ProjectionManager
{
    /** @var array<string, Projector> */
    private array $projectors = [];

    /**
     * @param iterable<Projector> $projectors
     */
    public function __construct(
        private EventStoreInterface $eventStore,
        private ProjectionStateRepositoryInterface $state,
        iterable $projectors
    ) {
        foreach ($projectors as $projector) {
            $this->projectors[$projector->name()] = $projector;
        }
    }

    /**
     * @return list<ProjectionStatus>
     */
    public function statuses(): array
    {
        $head = $this->eventStore->headPosition();
        $statuses = [];

        foreach ($this->projectors as $name => $projector) {
            $row = $this->state->find($name);

            $position = $row === null ? 0 : $row['lastProcessedPosition'];

            $statuses[] = new ProjectionStatus(
                name: $name,
                status: $row === null ? 'unknown' : $row['status'],
                lastProcessedPosition: $position,
                headPosition: $head,
                // Only events this projection actually consumes count as lag -
                // a bet row projection is not behind because a fee was booked.
                lag: $this->pendingFor($projector, $position),
                updatedAt: $row === null ? null : $row['updatedAt'],
                error: $row === null ? null : $row['error']
            );
        }

        return $statuses;
    }

    /**
     * Replays a projection, and everything downstream of it.
     *
     * Downstream is not optional. The read models are linked by foreign keys
     * with ON DELETE CASCADE, so emptying `participant` also empties
     * `membership`, `bet_row` and `fee`. Rebuilding only what was asked for
     * would leave those tables empty and the operator none the wiser, so the
     * rebuild continues through everything the reset can have taken with it.
     *
     * @return list<ProjectionStatus>
     */
    public function rebuild(string $name): array
    {
        $order = $this->orderedNames();
        $start = array_search($name, $order, true);

        if ($start === false) {
            throw new EntityNotFoundException("There is no projection called $name");
        }

        $rebuilt = [];
        foreach (array_slice($order, $start) as $downstream) {
            $rebuilt[] = $this->rebuildOne($downstream);
        }

        return $rebuilt;
    }

    /**
     * @return list<ProjectionStatus>
     */
    public function rebuildAll(): array
    {
        $order = $this->orderedNames();

        return $order === [] ? [] : $this->rebuild($order[0]);
    }

    /**
     * Not wrapped in a transaction on purpose: resetting the auto-increment
     * counters needs DDL, and DDL commits implicitly in MySQL, so a transaction
     * here would be a false promise. A rebuild is an operator action on a
     * projection that is already suspect.
     */
    private function rebuildOne(string $name): ProjectionStatus
    {
        $projector = $this->projectors[$name] ?? throw new EntityNotFoundException(
            "There is no projection called $name"
        );

        $this->state->markRebuilding($name);

        try {
            $projector->reset();

            $position = 0;
            $applied = 0;

            while (true) {
                $records = $this->eventStore->readFrom($position, 500, $projector->eventTypes());

                if ($records === []) {
                    break;
                }

                foreach ($records as $record) {
                    $projector->apply($record);
                    $position = $record->position;
                    $applied++;
                }
            }

            // Up to the head, not to the last event this projector cared about:
            // everything below the head has been considered, and skipping an
            // event of another type is still progress.
            $head = $this->eventStore->headPosition();
            $this->state->markRunning($name, $head);

            return new ProjectionStatus(
                name: $name,
                status: 'running',
                lastProcessedPosition: $head,
                headPosition: $head,
                lag: 0,
                updatedAt: null,
                error: sprintf('%d events applied', $applied)
            );
        } catch (Throwable $e) {
            $this->state->markFailed($name, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Dependency order, and it is load-bearing twice over: a reset cascades
     * downwards through the foreign keys, and the draw projection recomputes
     * ticket_row_match from the ticket's rows, so the ticket projection has to
     * have run first.
     *
     * @return list<string>
     */
    private function orderedNames(): array
    {
        $preferred = [
            'participant_read_model',
            'tipp_year_read_model',
            'bet_period_read_model',
            'bet_row_read_model',
            'ticket_read_model',
            'draw_read_model',
            'fee_read_model',
        ];

        $names = [];
        foreach ($preferred as $name) {
            if (isset($this->projectors[$name])) {
                $names[] = $name;
            }
        }

        foreach (array_keys($this->projectors) as $name) {
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function pendingFor(Projector $projector, int $position): int
    {
        return $this->eventStore->countFrom($position, $projector->eventTypes());
    }
}
