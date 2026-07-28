<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Exception\InvalidArgumentException;
use BettingGame\Domain\Repository\EventStoreInterface;

/**
 * OPS-03: everything that ever happened to one aggregate.
 *
 * This is the payoff of event sourcing for operations - not the current state
 * of a bet row, but that it was replaced on the 14th with a stated reason. No
 * other read model keeps that.
 */
final class GetAuditTrailHandler
{
    /**
     * The aggregate types that have a stream. Checked against a list rather
     * than passed through, because the type becomes part of a stream id and a
     * typo would otherwise answer "no history" instead of "no such type".
     *
     * @var list<string>
     */
    private const AGGREGATE_TYPES = [
        'participant',
        'tipp_year',
        'bet_period',
        'bet_row',
        'ticket',
        'draw',
        'fee',
    ];

    public function __construct(
        private EventStoreInterface $eventStore
    ) {
    }

    public function handle(GetAuditTrailQuery $query): QueryResult
    {
        if (!in_array($query->aggregateType, self::AGGREGATE_TYPES, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unknown aggregate type "%s", expected one of: %s',
                    $query->aggregateType,
                    implode(', ', self::AGGREGATE_TYPES)
                )
            );
        }

        $streamId = $query->aggregateType . '-' . $query->aggregateId;
        $records = $this->eventStore->recordsOf($streamId);

        if ($records === []) {
            throw new EntityNotFoundException("No event history for $streamId");
        }

        $events = [];
        foreach ($records as $record) {
            $events[] = [
                'position' => $record->position,
                'version' => $record->version,
                'eventId' => $record->event->eventId(),
                'eventType' => $record->event->eventType(),
                'occurredAt' => $record->event->occurredAt()->format('c'),
                'causationId' => $record->event->causationId(),
                'correlationId' => $record->event->correlationId(),
                'data' => $record->event->toArray(),
            ];
        }

        return new QueryResult([
            'aggregateType' => $query->aggregateType,
            'aggregateId' => $query->aggregateId,
            'streamId' => $streamId,
            'version' => $records[count($records) - 1]->version,
            'events' => $events,
        ]);
    }
}
