<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\InvalidArgumentException;

final class PredictionData
{
    private array $data;

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

    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return json_encode($this->data);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if ($data === null) {
            throw new InvalidArgumentException('Invalid JSON in prediction data');
        }
        return new self($data);
    }
}
