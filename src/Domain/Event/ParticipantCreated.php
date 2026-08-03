<?php

declare(strict_types=1);

namespace BettingGame\Domain\Event;

use DateTimeImmutable;

final class ParticipantCreated extends DomainEvent
{
    /**
     * @param int|null    $userId          the legacy `user` row, when there is one. Null for
     *                                     a participant the administrator entered directly:
     *                                     identity comes from Keycloak, and `user` predates it.
     * @param bool        $autoApproved    true where an administrator entered the participant
     *                                     (B-21), false where somebody registered themselves
     *                                     and is waiting for approval (E1-01)
     * @param string|null $keycloakSubject the account this participant *is*, from the token's
     *                                     `sub`. Set by a self-registration, null for an entry
     *                                     the administrator made for somebody else
     */
    public function __construct(
        private string $participantId,
        private ?int $userId,
        private string $displayName,
        private bool $autoApproved,
        private ?string $keycloakSubject = null,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $causationId = null,
        ?string $correlationId = null
    ) {
        parent::__construct($eventId, $occurredAt, $causationId, $correlationId);
    }

    public function aggregateId(): string
    {
        return $this->participantId;
    }

    public function aggregateType(): string
    {
        return 'participant';
    }

    public function eventType(): string
    {
        return 'participant.created';
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function autoApproved(): bool
    {
        return $this->autoApproved;
    }

    public function keycloakSubject(): ?string
    {
        return $this->keycloakSubject;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'participant_id' => $this->participantId,
            'user_id' => $this->userId,
            'display_name' => $this->displayName,
            'auto_approved' => $this->autoApproved,
            'keycloak_subject' => $this->keycloakSubject,
        ];
    }
}
