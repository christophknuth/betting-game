<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\InvalidArgumentException;

final class DisplayName
{
    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Display name cannot be empty');
        }
        if (strlen($trimmed) < 2) {
            throw new InvalidArgumentException('Display name must be at least 2 characters');
        }
        if (strlen($trimmed) > 50) {
            throw new InvalidArgumentException('Display name cannot exceed 50 characters');
        }
        $this->value = $trimmed;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
