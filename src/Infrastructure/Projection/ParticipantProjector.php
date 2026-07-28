<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Projection;

use BettingGame\Application\Projection\Projector;
use BettingGame\Domain\Repository\RecordedEvent;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Support\Row;

final class ParticipantProjector implements Projector
{
    public function __construct(private Db $db)
    {
    }

    public function name(): string
    {
        return 'participant_read_model';
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return ['participant.created', 'participant.approved'];
    }

    public function reset(): void
    {
        $this->db->execute('DELETE FROM participant');
    }

    public function apply(RecordedEvent $record): void
    {
        $data = $record->event->toArray();

        match ($record->event->eventType()) {
            'participant.created' => $this->db->execute(
                '
                INSERT INTO participant (participant_id, user_id, display_name, registered_at, is_active, version)
                VALUES (?, ?, ?, ?, ?, ?)
                ',
                [
                    Row::int($data, 'participant_id'),
                    Row::nullableInt($data, 'user_id'),
                    Row::string($data, 'display_name'),
                    $record->event->occurredAt()->format('Y-m-d H:i:s'),
                    Row::bool($data, 'auto_approved') ? 1 : 0,
                    $record->version,
                ]
            ),
            'participant.approved' => $this->db->execute(
                'UPDATE participant SET is_active = 1, version = ? WHERE participant_id = ?',
                [$record->version, Row::int($data, 'participant_id')]
            ),
            default => null,
        };
    }
}
