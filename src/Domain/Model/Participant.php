<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\ParticipantCreated;
use BettingGame\Domain\Event\ParticipantApproved;
use BettingGame\Domain\Event\ParticipantRenamed;
use BettingGame\Domain\Event\ParticipantStatusChanged;
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

    /**
     * B-25: correct the name this participant is listed under.
     *
     * A rename with no change is refused rather than ignored. An event that
     * describes no change does not belong in the history - the same rule B-06
     * applies to a replaced bet row and B-18 to a tipp year's status.
     */
    public function rename(DisplayName $displayName): void
    {
        if ($displayName->value() === $this->displayName->value()) {
            throw new BusinessRuleViolationException(
                'The new display name is identical to the current one'
            );
        }

        $previous = $this->displayName;
        $this->displayName = $displayName;
        $this->version++;

        $this->recordEvent(new ParticipantRenamed(
            (string) $this->id,
            $previous->value(),
            $displayName->value()
        ));
    }

    /**
     * B-25: set a participant inactive, or active again.
     *
     * Nothing that has happened is undone by this: past memberships, fees and
     * rows stay. It decides what may still happen - an inactive participant is
     * not offered for a tipp year and is refused by B-11.
     */
    public function changeStatus(bool $isActive): void
    {
        if ($isActive === $this->isActive) {
            throw new BusinessRuleViolationException(
                $isActive
                    ? 'This participant is already active'
                    : 'This participant is already inactive'
            );
        }

        $this->isActive = $isActive;
        $this->version++;

        $this->recordEvent(new ParticipantStatusChanged((string) $this->id, $isActive));
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
