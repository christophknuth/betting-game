<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Event\ParticipantCreated;
use BettingGame\Domain\Event\ParticipantApproved;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use DateTimeImmutable;

final class Participant
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];
    private int $version = 0;
    private int $originalVersion = 0;

    private function __construct(
        private int $id,
        private int $userId,
        private DisplayName $displayName,
        private bool $isActive,
        private DateTimeImmutable $registeredAt
    ) {
    }

    public static function create(
        int $id,
        int $userId,
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
        int $userId,
        DisplayName $displayName,
        bool $isActive,
        DateTimeImmutable $registeredAt,
        int $version
    ): self {
        $participant = new self($id, $userId, $displayName, $isActive, $registeredAt);
        $participant->version = $version;
        $participant->originalVersion = $version;

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


    private function recordEvent(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];
        return $events;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function userId(): int
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

    public function version(): int
    {
        return $this->version;
    }

    /**
     * Stream version this instance was loaded at - the expected version when appending.
     */
    public function originalVersion(): int
    {
        return $this->originalVersion;
    }
}
