<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\BetRowAssigned;
use BettingGame\Domain\Event\BetRowReplaced;
use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\ValueObject\LottoNumbers;
use DateTimeImmutable;

/**
 * The standing bet row of one participant for one tipp year.
 *
 * It takes part in every draw of the year automatically - the participant tips
 * once a year, not once per draw. That a row exists only once per participant
 * and year is enforced by a unique key in the schema, not by this class; what
 * lives here is the rule that changing it inside a running year is an
 * exception which needs a reason.
 */
final class BetRow
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];
    private int $version = 0;
    private int $originalVersion = 0;

    private function __construct(
        private int $id,
        private int $participantId,
        private int $tippYearId,
        private LottoNumbers $numbers,
        private DateTimeImmutable $assignedAt
    ) {
    }

    public static function assign(
        int $id,
        int $participantId,
        int $tippYearId,
        LottoNumbers $numbers
    ): self {
        $row = new self($id, $participantId, $tippYearId, $numbers, new DateTimeImmutable());

        $row->recordEvent(new BetRowAssigned(
            (string) $id,
            $participantId,
            $tippYearId,
            $numbers->toArray()
        ));

        return $row;
    }

    /**
     * Rehydrates from the read model without recording events.
     */
    public static function fromProjection(
        int $id,
        int $participantId,
        int $tippYearId,
        LottoNumbers $numbers,
        DateTimeImmutable $assignedAt,
        int $version
    ): self {
        $row = new self($id, $participantId, $tippYearId, $numbers, $assignedAt);
        $row->version = $version;
        $row->originalVersion = $version;

        return $row;
    }

    /**
     * Corrects the row inside a running tipp year.
     *
     * Regularly a row changes only at the turn of the year, so a reason is
     * mandatory - the event records an exception, not routine behaviour.
     * Tickets already submitted keep their snapshot and are unaffected.
     */
    public function replace(LottoNumbers $numbers, string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessRuleViolationException(
                'Replacing a bet row within a running tipp year requires a reason'
            );
        }

        if ($this->numbers->equals($numbers)) {
            throw new BusinessRuleViolationException('The new numbers are identical to the current ones');
        }

        $previous = $this->numbers->toArray();
        $this->numbers = $numbers;
        $this->version++;

        $this->recordEvent(new BetRowReplaced(
            (string) $this->id,
            $previous,
            $numbers->toArray(),
            $reason
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

    public function participantId(): int
    {
        return $this->participantId;
    }

    public function tippYearId(): int
    {
        return $this->tippYearId;
    }

    public function numbers(): LottoNumbers
    {
        return $this->numbers;
    }

    public function assignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
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
