<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Projection;

use BettingGame\Application\Projection\Projector;
use BettingGame\Domain\Repository\RecordedEvent;
use BettingGame\Domain\ValueObject\ParticipantStatus;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Support\Row;

final class ParticipantProjector implements Projector
{
    public const NAME = 'participant_read_model';

    public const EVENT_CREATED = 'participant.created';

    public const EVENT_APPROVED = 'participant.approved';

    public const EVENT_RENAMED = 'participant.renamed';

    public const EVENT_STATUS_CHANGED = 'participant.status_changed';

    public function __construct(private Db $db)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return [
            self::EVENT_CREATED,
            self::EVENT_APPROVED,
            self::EVENT_RENAMED,
            self::EVENT_STATUS_CHANGED,
        ];
    }

    public function reset(): void
    {
        $this->db->execute('DELETE FROM participant');
    }

    public function apply(RecordedEvent $record): void
    {
        $data = $record->event->toArray();

        match ($record->event->eventType()) {
            self::EVENT_CREATED => $this->db->execute(
                '
                INSERT INTO participant (
                    participant_id, user_id, display_name, keycloak_subject, registered_at, status, version
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ',
                [
                    Row::int($data, 'participant_id'),
                    Row::nullableInt($data, 'user_id'),
                    Row::string($data, 'display_name'),
                    // Absent from every participant created before E1-01 - the
                    // event log is immutable, and demanding it would break a
                    // rebuild on all of them.
                    Row::nullableString($data, 'keycloak_subject'),
                    $record->event->occurredAt()->format('Y-m-d H:i:s'),
                    // What the administrator enters is approved by the act of
                    // entering it; a self-registration waits (E1-01).
                    Row::bool($data, 'auto_approved')
                        ? ParticipantStatus::ACTIVE
                        : ParticipantStatus::PENDING,
                    $record->version,
                ]
            ),
            // E1-01: the administrator said yes to a registration. B-25's
            // status change lands in the same column and is a different thing -
            // somebody leaving, or coming back.
            self::EVENT_APPROVED => $this->db->execute(
                'UPDATE participant SET status = ?, version = ? WHERE participant_id = ?',
                [ParticipantStatus::ACTIVE, $record->version, Row::int($data, 'participant_id')]
            ),
            self::EVENT_RENAMED => $this->db->execute(
                'UPDATE participant SET display_name = ?, version = ? WHERE participant_id = ?',
                [
                    Row::string($data, 'display_name'),
                    $record->version,
                    Row::int($data, 'participant_id'),
                ]
            ),
            self::EVENT_STATUS_CHANGED => $this->db->execute(
                'UPDATE participant SET status = ?, version = ? WHERE participant_id = ?',
                [
                    Row::bool($data, 'is_active')
                        ? ParticipantStatus::ACTIVE
                        : ParticipantStatus::INACTIVE,
                    $record->version,
                    Row::int($data, 'participant_id'),
                ]
            ),
            default => null,
        };
    }
}
