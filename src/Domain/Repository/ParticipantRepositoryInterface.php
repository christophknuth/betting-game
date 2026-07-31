<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\Participant;

interface ParticipantRepositoryInterface
{
    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /** @return list<array<string, mixed>> */
    public function findAll(): array;

    public function findParticipant(int $id): ?Participant;

    public function exists(int $id): bool;

    public function save(Participant $participant): void;

    public function nextIdentity(): int;
}
