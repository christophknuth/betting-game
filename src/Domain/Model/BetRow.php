<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\BetRowAssigned;
use BettingGame\Domain\Event\BetRowReplaced;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\ValueObject\LottoNumbers;
use DateTimeImmutable;

/**
 * The standing bet row of one participant for one bet period.
 *
 * It takes part in every draw of its period automatically - the participant
 * tips once per period, not once per draw. How long a period lasts is up to the
 * administrator: one period spanning the tipp year means one row per year,
 * twelve monthly periods mean a row can be changed every month.
 *
 * That a row exists only once per participant and period is enforced by a
 * unique key in the schema, not by this class; what lives here is the rule that
 * changing it inside a running period is an exception which needs a reason.
 */
final class BetRow
{
    use RecordsEvents;

    private function __construct(
        private int $id,
        private int $participantId,
        private int $betPeriodId,
        private LottoNumbers $numbers,
        private DateTimeImmutable $assignedAt
    ) {
    }

    public static function assign(
        int $id,
        int $participantId,
        int $betPeriodId,
        LottoNumbers $numbers
    ): self {
        $row = new self($id, $participantId, $betPeriodId, $numbers, new DateTimeImmutable());

        $row->recordEvent(new BetRowAssigned(
            (string) $id,
            $participantId,
            $betPeriodId,
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
        int $betPeriodId,
        LottoNumbers $numbers,
        DateTimeImmutable $assignedAt,
        int $version
    ): self {
        $row = new self($id, $participantId, $betPeriodId, $numbers, $assignedAt);
        $row->markCommitted($version);

        return $row;
    }

    /**
     * Corrects the row inside a running bet period.
     *
     * Regularly a row changes only when the next period starts, so a reason is
     * mandatory - the event records an exception, not routine behaviour.
     * Tickets already submitted keep their snapshot and are unaffected.
     */
    public function replace(LottoNumbers $numbers, string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessRuleViolationException(
                'Replacing a bet row within a running bet period requires a reason'
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

    public function id(): int
    {
        return $this->id;
    }

    public function participantId(): int
    {
        return $this->participantId;
    }

    public function betPeriodId(): int
    {
        return $this->betPeriodId;
    }

    public function numbers(): LottoNumbers
    {
        return $this->numbers;
    }

    public function assignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
    }
}
