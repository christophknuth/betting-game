<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\InvalidArgumentException;

final class PredictionData
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Prediction data cannot be empty');
        }

        // Validate JSON serializability
        if (json_encode($data) === false) {
            throw new InvalidArgumentException('Prediction data must be JSON serializable');
        }

        $this->data = $data;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        $json = json_encode($this->data);

        // The constructor already rejected non-serializable data, so this branch
        // is unreachable in practice - the type system just cannot know that.
        if ($json === false) {
            throw new InvalidArgumentException('Prediction data is not JSON serializable');
        }

        return $json;
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON in prediction data');
        }

        /** @var array<string, mixed> $data */
        return new self($data);
    }
}
