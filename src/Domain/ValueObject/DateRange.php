<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;

/**
 * A closed date range - both ends belong to the range.
 *
 * Used wherever the administrator picks boundaries freely: the tipp year
 * itself and the validity period of a bet row.
 */
final class DateRange
{
    private DateTimeImmutable $start;
    private DateTimeImmutable $end;

    public function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
        // Only the date matters; a time of day would make comparisons surprising
        $this->start = $start->setTime(0, 0);
        $this->end = $end->setTime(0, 0);

        if ($this->end < $this->start) {
            throw new InvalidArgumentException('End date must not be before start date');
        }
    }

    public static function fromStrings(string $start, string $end): self
    {
        return new self(new DateTimeImmutable($start), new DateTimeImmutable($end));
    }

    public function start(): DateTimeImmutable
    {
        return $this->start;
    }

    public function end(): DateTimeImmutable
    {
        return $this->end;
    }

    public function contains(DateTimeImmutable $date): bool
    {
        $day = $date->setTime(0, 0);

        return $day >= $this->start && $day <= $this->end;
    }

    /**
     * Two ranges overlap when neither ends before the other begins.
     */
    public function overlaps(self $other): bool
    {
        return $this->start <= $other->end && $other->start <= $this->end;
    }

    public function covers(self $other): bool
    {
        return $this->start <= $other->start && $this->end >= $other->end;
    }

    public function dayCount(): int
    {
        return (int) $this->start->diff($this->end)->days + 1;
    }

    public function equals(self $other): bool
    {
        return $this->start == $other->start && $this->end == $other->end;
    }

    public function __toString(): string
    {
        return $this->start->format('Y-m-d') . ' .. ' . $this->end->format('Y-m-d');
    }
}
