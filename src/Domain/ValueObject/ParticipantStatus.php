<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\InvalidArgumentException;

/**
 * Where a participant stands with the syndicate.
 *
 *   pending -> active -> inactive
 *
 * `pending` is what E1-01 needed and a boolean could not express: somebody has
 * registered themselves and is waiting for the administrator to say yes. Until
 * that happens they are not a member of anything, and they are not somebody who
 * left either - and a roster that cannot tell those two apart would either hide
 * the request or offer a stranger for a tipp year.
 *
 * `active` is what an administrator's own entry starts as (B-21): whatever they
 * record is approved by the act of recording it.
 *
 * `inactive` says "plays no more" - either a refused registration or somebody
 * who left. Nothing that has been booked is undone by it (B-25).
 */
final class ParticipantStatus
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';

    private const VALID = [self::PENDING, self::ACTIVE, self::INACTIVE];

    public function __construct(private string $value)
    {
        if (!in_array($value, self::VALID, true)) {
            throw new InvalidArgumentException("Unknown participant status: $value");
        }
    }

    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    public static function active(): self
    {
        return new self(self::ACTIVE);
    }

    public static function inactive(): self
    {
        return new self(self::INACTIVE);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isPending(): bool
    {
        return $this->value === self::PENDING;
    }

    /** The one state that may join a tipp year, be assigned a row and be picked. */
    public function isActive(): bool
    {
        return $this->value === self::ACTIVE;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
