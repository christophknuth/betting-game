<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\Participant;

interface ParticipantRepositoryInterface
{
    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /**
     * @param string|null $status null for everybody, or one of ParticipantStatus
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(?string $status = null): array;

    public function findParticipant(int $id): ?Participant;

    /** E1-01: the participant behind a Keycloak account, if there is one. */
    public function findByKeycloakSubject(string $subject): ?Participant;

    public function exists(int $id): bool;

    public function save(Participant $participant): void;

    public function nextIdentity(): int;
}
