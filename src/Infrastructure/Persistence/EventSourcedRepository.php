<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Repository\EventStoreInterface;

/**
 * What every aggregate repository here does the same way.
 *
 * Writing an aggregate is two steps: append the new events to its stream under
 * optimistic locking, then bring the read model in line. The projection version
 * mirrors the stream version, so the next load knows which version to expect
 * when it appends - that is the whole reason the projection carries one.
 */
abstract class EventSourcedRepository
{
    public function __construct(
        protected Db $db,
        protected EventStoreInterface $eventStore
    ) {
    }

    /**
     * Appends the events and returns the version the projection should record.
     *
     * @param list<DomainEvent> $events
     */
    protected function append(string $streamId, array $events, int $expectedVersion): int
    {
        if ($events !== []) {
            $this->eventStore->append($streamId, $events, $expectedVersion);
        }

        return $expectedVersion + count($events);
    }

    /**
     * Runs the append and the projection write as one unit.
     *
     * Both have to succeed together. An append without its projection would
     * leave the event store describing a row that does not exist - which is
     * exactly what happens when a unique key rejects a second bet row for the
     * same period after its event was already written.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    protected function transactionally(callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = $work();

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * MAX(id) + 1.
     *
     * Two concurrent writers can pick the same id here; the unique key on the
     * target table is what actually rejects the loser, and the caller retries.
     * The table and column are class constants of the calling repository, never
     * request data.
     */
    protected function nextId(string $table, string $column): int
    {
        $row = $this->db->fetchOne("SELECT COALESCE(MAX($column), 0) + 1 AS next_id FROM $table");

        return $row !== null ? Row::int($row, 'next_id') : 1;
    }

    /**
     * Finds the first released event of a given class, or null.
     *
     * @template T of DomainEvent
     *
     * @param list<DomainEvent> $events
     * @param class-string<T>   $class
     *
     * @return T|null
     */
    protected function firstOf(array $events, string $class): ?DomainEvent
    {
        foreach ($events as $event) {
            if ($event instanceof $class) {
                return $event;
            }
        }

        return null;
    }
}
