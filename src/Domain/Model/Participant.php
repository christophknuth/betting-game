<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\ParticipantCreated;
use BettingGame\Domain\Event\ParticipantApproved;
use BettingGame\Domain\Event\ParticipantRenamed;
use BettingGame\Domain\Event\ParticipantStatusChanged;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Domain\ValueObject\ParticipantStatus;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use DateTimeImmutable;

/**
 * Somebody who plays, or would like to.
 *
 * Two ways in, and the difference is who vouched for whom:
 *
 * - **The administrator enters somebody** (B-21). Whatever they record is
 *   approved by the act of recording it, so the participant is `active` at
 *   once and carries no account of their own.
 * - **Somebody registers themselves** (E1-01). They arrive `pending` with the
 *   Keycloak subject of the account they signed in with, and an administrator
 *   decides. Until then they are a request, not a member.
 */
final class Participant
{
    use RecordsEvents;

    private function __construct(
        private int $id,
        private ?int $userId,
        private DisplayName $displayName,
        private ParticipantStatus $status,
        private ?string $keycloakSubject,
        private DateTimeImmutable $registeredAt
    ) {
    }

    /**
     * B-21: the administrator enters somebody.
     *
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
            $autoApprove ? ParticipantStatus::active() : ParticipantStatus::pending(),
            null,
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
     * E1-01: somebody registers themselves.
     *
     * The Keycloak subject is what makes this self-service rather than a form
     * an administrator has to copy: it is the account that asked, so the API
     * can recognise the same person on the next request without anybody
     * entering an id into the realm by hand.
     */
    public static function register(int $id, string $keycloakSubject, DisplayName $displayName): self
    {
        if (trim($keycloakSubject) === '') {
            throw new BusinessRuleViolationException('A registration needs the account it came from');
        }

        $participant = new self(
            $id,
            null,
            $displayName,
            ParticipantStatus::pending(),
            $keycloakSubject,
            new DateTimeImmutable()
        );

        $participant->recordEvent(new ParticipantCreated(
            (string) $id,
            null,
            $displayName->value(),
            false,
            $keycloakSubject
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
        ParticipantStatus $status,
        ?string $keycloakSubject,
        DateTimeImmutable $registeredAt,
        int $version
    ): self {
        $participant = new self($id, $userId, $displayName, $status, $keycloakSubject, $registeredAt);
        $participant->markCommitted($version);

        return $participant;
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
     * B-25 and E1-01: set a participant active or inactive.
     *
     * One command, two facts. Saying yes to somebody who is `pending` is the
     * **approval of their registration** and is recorded as one; every other
     * move is an administrator changing who still plays. The distinction is
     * worth an event type of its own - an audit trail that cannot tell an
     * approval from a reactivation has lost the more interesting of the two.
     *
     * Nothing that has happened is undone by going inactive: past memberships,
     * fees and rows stay. It decides what may still happen - an inactive or
     * pending participant is not offered for a tipp year and is refused by B-11.
     */
    public function changeStatus(bool $isActive): void
    {
        // Compared as the status it would become, not as a boolean: refusing a
        // pending registration moves it to `inactive`, and "is not active
        // already" would have called that no change at all.
        $target = $isActive ? ParticipantStatus::active() : ParticipantStatus::inactive();

        if ($target->equals($this->status)) {
            throw new BusinessRuleViolationException(
                $isActive
                    ? 'This participant is already active'
                    : 'This participant is already inactive'
            );
        }

        $wasPending = $this->status->isPending();

        $this->status = $target;
        $this->version++;

        $this->recordEvent(
            $wasPending && $isActive
                ? new ParticipantApproved((string) $this->id)
                : new ParticipantStatusChanged((string) $this->id, $isActive)
        );
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

    public function status(): ParticipantStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /** The Keycloak account this participant is, where they registered themselves. */
    public function keycloakSubject(): ?string
    {
        return $this->keycloakSubject;
    }

    public function registeredAt(): DateTimeImmutable
    {
        return $this->registeredAt;
    }
}
