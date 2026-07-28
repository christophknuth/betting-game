<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Repository\CommandLogRepositoryInterface;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Support\Row;

/**
 * OPS-01: what became of a command.
 *
 * The API describes commands as asynchronous, but this implementation writes
 * synchronously - by the time the caller has the 202, the command is already
 * completed or failed. The endpoint is still worth having: it is where an
 * idempotent retry can look up what the original attempt produced, and it keeps
 * the contract in place for when writing does move off the request.
 */
final class GetCommandStatusHandler
{
    public function __construct(
        private CommandLogRepositoryInterface $commands,
        private EventStoreInterface $eventStore
    ) {
    }

    public function handle(GetCommandStatusQuery $query): QueryResult
    {
        $row = $this->commands->find($query->commandId);

        if ($row === null) {
            throw new EntityNotFoundException("Command {$query->commandId} is unknown");
        }

        $error = Row::nullableString($row, 'error_message');

        return new QueryResult([
            'commandId' => Row::string($row, 'command_id'),
            'commandType' => Row::string($row, 'command_type'),
            'status' => Row::string($row, 'status'),
            'aggregateType' => Row::nullableString($row, 'aggregate_type'),
            'aggregateId' => Row::nullableString($row, 'aggregate_id'),
            'resourceId' => Row::nullableInt($row, 'resource_id'),
            'httpStatus' => Row::nullableInt($row, 'http_status'),
            // Writes are synchronous, so anything that completed is by
            // definition reflected in the read models already.
            'projectionsUpToDate' => Row::string($row, 'status') === 'completed',
            'eventStorePosition' => $this->eventStore->headPosition(),
            'acceptedAt' => Row::nullableString($row, 'accepted_at'),
            'completedAt' => Row::nullableString($row, 'completed_at'),
            'error' => $error === null ? null : ['message' => $error],
        ]);
    }
}
