<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\InvalidArgumentException;

final class GameStatus
{
    private const VALID_STATUSES = ['upcoming', 'active', 'ended', 'cancelled'];

    private string $value;

    public function __construct(string $value)
    {
        if (!in_array($value, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Invalid game status. Must be one of: ' . implode(', ', self::VALID_STATUSES)
            );
        }
        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isActive(): bool
    {
        return $this->value === 'active';
    }

    public function isEnded(): bool
    {
        return $this->value === 'ended';
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
