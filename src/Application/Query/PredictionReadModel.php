<?php

declare(strict_types=1);

namespace BettingGame\Application\Query;

final class PredictionReadModel
{
    public function __construct(
        public readonly string $predictionId,
        public readonly int $participantId,
        public readonly int $eventId,
        public readonly string $eventName,
        public readonly array $predictionData,
        public readonly string $submittedAt,
        public readonly ?string $updatedAt,
        public readonly string $deadline,
        public readonly string $status,
        public readonly bool $isEditable,
        public readonly ?array $result = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'predictionId' => $this->predictionId,
            'participantId' => $this->participantId,
            'eventId' => $this->eventId,
            'eventName' => $this->eventName,
            'predictionData' => $this->predictionData,
            'submittedAt' => $this->submittedAt,
            'updatedAt' => $this->updatedAt,
            'deadline' => $this->deadline,
            'status' => $this->status,
            'isEditable' => $this->isEditable,
            'result' => $this->result,
        ];
    }
}
