<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\ParticipantCreated;
use BettingGame\Domain\Event\ParticipantApproved;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use DateTimeImmutable;

final class Participant
{
    use RecordsEvents;

    private function __construct(
        private int $id,
        private ?int $userId,
        private DisplayName $displayName,
        private bool $isActive,
        private DateTimeImmutable $registeredAt
    ) {
    }

    /**
     * @param int|null $userId the legacy `user` row this participant belongs to,
     *                         if any. The administrator creates participants
     *                         without one: identity comes from Keycloak, and
     *                         `user` predates it and is no longer written.
     */
    public static function create(
        int $id,
        ?int $userId,
        DisplayName $displayName,
        bool $autoApprove = false
    ): self {
        $participant = new self(
            $id,
            $userId,
            $displayName,
            $autoApprove,
            new DateTimeImmutable()
        );

        $participant->recordEvent(new ParticipantCreated(
            (string) $id,
            $userId,
            $displayName->value(),
            $autoApprove
        ));

        return $participant;
    }

    /**
     * Rehydrates a participant from the read model without recording events.
     */
    public static function reconstitute(
        int $id,
        ?int $userId,
        DisplayName $displayName,
        bool $isActive,
        DateTimeImmutable $registeredAt,
        int $version
    ): self {
        $participant = new self($id, $userId, $displayName, $isActive, $registeredAt);
        $participant->markCommitted($version);

        return $participant;
    }

    public function approve(): void
    {
        if ($this->isActive) {
            throw new BusinessRuleViolationException('Participant is already active');
        }

        $this->isActive = true;
        $this->version++;

        $this->recordEvent(new ParticipantApproved(
            (string) $this->id
        ));
    }


    public function id(): int
    {
        return $this->id;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function displayName(): DisplayName
    {
        return $this->displayName;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function registeredAt(): DateTimeImmutable
    {
        return $this->registeredAt;
    }
}
