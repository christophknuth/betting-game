<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Support\Row;
use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Exception\DuplicateEntryException;
use BettingGame\Domain\Repository\EventStoreInterface;
use PDOException;

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

            throw $e instanceof PDOException ? self::translate($e) : $e;
        }
    }

    /**
     * Turns a rejected unique key into a domain exception.
     *
     * Rules like "one bet row per participant and period" live in the schema,
     * so the database is where they fire. Translating here keeps PDO out of the
     * application layer - a handler should not have to read SQLSTATE to find
     * out that a business rule said no.
     */
    private static function translate(PDOException $e): \Throwable
    {
        if ($e->getCode() !== '23000') {
            return $e;
        }

        $constraint = '';
        if (preg_match("/for key '(?:.*\.)?([^']+)'/", $e->getMessage(), $matches) === 1) {
            $constraint = $matches[1];
        }

        return new DuplicateEntryException($e->getMessage(), $constraint, $e);
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
