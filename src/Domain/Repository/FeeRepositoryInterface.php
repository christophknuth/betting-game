<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\Fee;

interface FeeRepositoryInterface
{
    public function find(int $id): ?Fee;

    public function save(Fee $fee): void;

    public function nextIdentity(): int;

    public function findByParticipantAndTicket(int $participantId, int $ticketId): ?Fee;

    /** @return list<Fee> */
    public function findByTicket(int $ticketId): array;

    /**
     * B-03: the participant's fees with the ticket period they belong to,
     * most recent first.
     *
     * @return list<array<string, mixed>>
     */
    public function findByParticipant(int $participantId): array;

    public function openTotalOf(int $participantId): float;
}
