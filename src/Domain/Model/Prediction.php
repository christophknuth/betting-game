<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\DomainEvent;
use BettingGame\Domain\Event\PredictionSubmitted;
use BettingGame\Domain\Event\PredictionUpdated;
use BettingGame\Domain\Event\PredictionEvaluated;
use BettingGame\Domain\Exception\DeadlinePassedException;
use BettingGame\Domain\ValueObject\ParticipantId;
use BettingGame\Domain\ValueObject\EventId;
use BettingGame\Domain\ValueObject\PredictionData;
use DateTimeImmutable;

final class Prediction
{
    private array $recordedEvents = [];
    private int $version = 0;

    private function __construct(
        private string $id,
        private ParticipantId $participantId,
        private EventId $eventId,
        private PredictionData $predictionData,
        private DateTimeImmutable $submittedAt,
        private ?DateTimeImmutable $updatedAt = null,
        private ?int $pointsEarned = null,
        private ?float $prizeAmount = null,
        private bool $evaluated = false
    ) {
    }

    public static function submit(
        string $id,
        ParticipantId $participantId,
        EventId $eventId,
        PredictionData $predictionData,
        DateTimeImmutable $deadline
    ): self {
        $now = new DateTimeImmutable();
        
        if ($now > $deadline) {
            throw new DeadlinePassedException('Prediction deadline has passed');
        }

        $prediction = new self(
            $id,
            $participantId,
            $eventId,
            $predictionData,
            $now
        );

        $prediction->recordEvent(new PredictionSubmitted(
            $id,
            $participantId->value(),
            $eventId->value(),
            $predictionData->toArray()
        ));

        return $prediction;
    }

    public function update(PredictionData $newData, DateTimeImmutable $deadline): void
    {
        if ($this->evaluated) {
            throw new DeadlinePassedException('Cannot update an evaluated prediction');
        }

        $now = new DateTimeImmutable();
        if ($now > $deadline) {
            throw new DeadlinePassedException('Prediction deadline has passed');
        }

        $this->predictionData = $newData;
        $this->updatedAt = $now;
        $this->version++;

        $this->recordEvent(new PredictionUpdated(
            $this->id,
            $newData->toArray(),
            $this->version
        ));
    }

    public function evaluate(int $points, ?float $prizeAmount = null): void
    {
        $this->pointsEarned = $points;
        $this->prizeAmount = $prizeAmount;
        $this->evaluated = true;

        $this->recordEvent(new PredictionEvaluated(
            $this->id,
            $points,
            $prizeAmount
        ));
    }

    private function recordEvent(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * @return DomainEvent[]
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];
        return $events;
    }

    // Getters
    public function id(): string
    {
        return $this->id;
    }

    public function participantId(): ParticipantId
    {
        return $this->participantId;
    }

    public function eventId(): EventId
    {
        return $this->eventId;
    }

    public function predictionData(): PredictionData
    {
        return $this->predictionData;
    }

    public function submittedAt(): DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function pointsEarned(): ?int
    {
        return $this->pointsEarned;
    }

    public function prizeAmount(): ?float
    {
        return $this->prizeAmount;
    }

    public function isEvaluated(): bool
    {
        return $this->evaluated;
    }

    // Reconstitution from events
    public static function reconstitute(array $events): self
    {
        $prediction = null;

        foreach ($events as $event) {
            if ($event instanceof PredictionSubmitted) {
                $prediction = new self(
                    $event->aggregateId(),
                    new ParticipantId($event->participantId()),
                    new EventId($event->gameEventId()),
                    new PredictionData($event->predictionData()),
                    $event->occurredAt()
                );
            } elseif ($event instanceof PredictionUpdated && $prediction !== null) {
                $prediction->predictionData = new PredictionData($event->predictionData());
                $prediction->updatedAt = $event->occurredAt();
                $prediction->version = $event->version();
            } elseif ($event instanceof PredictionEvaluated && $prediction !== null) {
                $prediction->pointsEarned = $event->pointsEarned();
                $prediction->prizeAmount = $event->prizeAmount();
                $prediction->evaluated = true;
            }
        }

        if ($prediction === null) {
            throw new \RuntimeException('Cannot reconstitute prediction from empty event stream');
        }

        return $prediction;
    }
}
