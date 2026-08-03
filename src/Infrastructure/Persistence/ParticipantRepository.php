<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Support\Row;
use BettingGame\Domain\Model\Participant;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Domain\ValueObject\ParticipantStatus;
use DateTimeImmutable;
use BettingGame\Infrastructure\Projection\ParticipantProjector;

final class ParticipantRepository extends EventSourcedRepository implements ParticipantRepositoryInterface
{
    private const STREAM_PREFIX = 'participant-';

    /** The read model this repository keeps current; see EventSourcedRepository. */
    protected function projectionName(): string
    {
        return ParticipantProjector::NAME;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM participant WHERE participant_id = ?', [$id]);
    }

    /**
     * @param string|null $status null for everybody, or one of ParticipantStatus
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $status = null): array
    {
        // By name, not by id: this feeds a picker, and a reader looking for
        // someone scans names.
        if ($status === null) {
            return $this->db->fetchAll(
                'SELECT * FROM participant ORDER BY display_name, participant_id'
            );
        }

        return $this->db->fetchAll(
            'SELECT * FROM participant WHERE status = ? ORDER BY display_name, participant_id',
            [$status]
        );
    }

    /**
     * The participant behind a Keycloak account (E1-01).
     *
     * This is what makes a self-registration self-service: identity comes off
     * the token either way, but without it the link would have to be typed
     * into the realm as an attribute by hand.
     */
    public function findByKeycloakSubject(string $subject): ?Participant
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM participant WHERE keycloak_subject = ?',
            [$subject]
        );

        return $row === null ? null : $this->toAggregate($row);
    }

    public function findParticipant(int $id): ?Participant
    {
        $row = $this->findById($id);

        return $row === null ? null : $this->toAggregate($row);
    }

    /** @param array<string, mixed> $row */
    private function toAggregate(array $row): Participant
    {
        return Participant::reconstitute(
            id: Row::int($row, 'participant_id'),
            userId: Row::nullableInt($row, 'user_id'),
            displayName: new DisplayName(Row::string($row, 'display_name')),
            status: new ParticipantStatus(Row::string($row, 'status')),
            keycloakSubject: Row::nullableString($row, 'keycloak_subject'),
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
            // The subject is not updated: an account is settled when the
            // participant is created and never moves to somebody else.
            $this->db->execute(
                '
                UPDATE participant
                SET display_name = ?, status = ?, version = ?
                WHERE participant_id = ?
                ',
                [
                    $participant->displayName()->value(),
                    $participant->status()->value(),
                    $version,
                    $participant->id(),
                ]
            );

            return;
        }

        // uk_user must reject a second participant for the same account, and
        // uk_keycloak_subject a second registration from the same login
        $this->db->execute(
            '
            INSERT INTO participant (
                participant_id, user_id, display_name, keycloak_subject, registered_at, status, version
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ',
            [
                $participant->id(),
                $participant->userId(),
                $participant->displayName()->value(),
                $participant->keycloakSubject(),
                $participant->registeredAt()->format('Y-m-d H:i:s'),
                $participant->status()->value(),
                $version,
            ]
        );
    }
}
