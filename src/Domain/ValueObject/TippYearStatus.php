<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\InvalidArgumentException;

/**
 * Lifecycle of a tipp year.
 *
 *   planned -> running -> closed -> distributed
 *
 * Only a closed year can be distributed, and distribution is final.
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
