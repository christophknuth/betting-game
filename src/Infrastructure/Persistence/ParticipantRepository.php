<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Support\Row;
use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\ValueObject\DisplayName;
use DateTimeImmutable;

final class ParticipantRepository extends EventSourcedRepository implements ParticipantRepositoryInterface
{
    private const STREAM_PREFIX = 'participant-';

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM participant WHERE participant_id = ?', [$id]);
    }

    public function findParticipant(int $id): ?Participant
    {
        $row = $this->findById($id);

        if ($row === null) {
            return null;
        }

        return Participant::reconstitute(
            id: Row::int($row, 'participant_id'),
            userId: Row::int($row, 'user_id'),
            displayName: new DisplayName(Row::string($row, 'display_name')),
            isActive: Row::bool($row, 'is_active'),
            registeredAt: new DateTimeImmutable(Row::string($row, 'registered_at')),
            version: Row::int($row, 'version')
        );
    }

    public function exists(int $id): bool
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM participant WHERE participant_id = ?',
            [$id]
        );

        return $row !== null && Row::int($row, 'cnt') > 0;
    }

    public function save(Participant $participant): void
    {
        $exists = $participant->isPersisted();

        $version = $this->transactionally(function () use ($participant, $exists): int {
            $version = $this->append(
                self::STREAM_PREFIX . $participant->id(),
                $participant->releaseEvents(),
                $participant->originalVersion()
            );

            $this->writeProjection($participant, $version, $exists);

            return $version;
        });

        $participant->markCommitted($version);
    }

    public function nextIdentity(): int
    {
        return $this->nextId('participant', 'participant_id');
    }

    private function writeProjection(Participant $participant, int $version, bool $exists): void
    {
        if ($exists) {
            $this->db->execute(
                '
                UPDATE participant
                SET display_name = ?, is_active = ?, version = ?
                WHERE participant_id = ?
                ',
                [
                    $participant->displayName()->value(),
                    $participant->isActive() ? 1 : 0,
                    $version,
                    $participant->id(),
                ]
            );

            return;
        }

        // uk_user must reject a second participant for the same account
        $this->db->execute(
            '
            INSERT INTO participant (participant_id, user_id, display_name, registered_at, is_active, version)
            VALUES (?, ?, ?, ?, ?, ?)
            ',
            [
                $participant->id(),
                $participant->userId(),
                $participant->displayName()->value(),
                $participant->registeredAt()->format('Y-m-d H:i:s'),
                $participant->isActive() ? 1 : 0,
                $version,
            ]
        );
    }
}
