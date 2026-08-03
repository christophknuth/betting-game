<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\Participant;

interface ParticipantRepositoryInterface
{
    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /**
     * @param bool|null $isActive null for everybody, true for the ones still playing
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(?bool $isActive = null): array;

    public function findParticipant(int $id): ?Participant;

    public function exists(int $id): bool;

    public function save(Participant $participant): void;

    public function nextIdentity(): int;
}
