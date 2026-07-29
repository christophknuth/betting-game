<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\InvalidArgumentException;

/**
 * Lifecycle of a tipp year.
 *
 *   planned -> running -> closed -> distributed
 *
 * That is the intended course, not an enforced one: the administrator may move
 * a year to any status, including backwards, because a year closed too early
 * has to be reopenable (see TippYear::changeStatusTo). Two rules survive that,
 * and neither lives here:
 *
 *   - at most one year is `running`      (unique key on tipp_year)
 *   - at most one distribution per year  (unique key on payout)
 *
 * Both are keys rather than checks, because a check cannot hold against two
 * concurrent requests.
 */
final class TippYearStatus
{
    public const PLANNED = 'planned';
    public const RUNNING = 'running';
    public const CLOSED = 'closed';
    public const DISTRIBUTED = 'distributed';

    private const VALID = [self::PLANNED, self::RUNNING, self::CLOSED, self::DISTRIBUTED];

    public function __construct(private string $value)
    {
        if (!in_array($value, self::VALID, true)) {
            throw new InvalidArgumentException("Unknown tipp year status: $value");
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isRunning(): bool
    {
        return $this->value === self::RUNNING;
    }

    public function isClosed(): bool
    {
        return $this->value === self::CLOSED;
    }

    public function isDistributed(): bool
    {
        return $this->value === self::DISTRIBUTED;
    }

    /**
     * A ticket may only be submitted while the year is running.
     */
    public function acceptsTickets(): bool
    {
        return $this->value === self::RUNNING;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
