<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Model\Prediction;
use BettingGame\Domain\Repository\PredictionRepositoryInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Repository\GameEventRepositoryInterface;
use BettingGame\Domain\ValueObject\ParticipantId;
use BettingGame\Domain\ValueObject\EventId;
use BettingGame\Domain\ValueObject\PredictionData;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Exception\DuplicatePredictionException;

final class SubmitPredictionHandler
{
    public function __construct(
        private PredictionRepositoryInterface $predictionRepository,
        private ParticipantRepositoryInterface $participantRepository,
        private GameEventRepositoryInterface $eventRepository
    ) {
    }

    public function handle(SubmitPredictionCommand $command): CommandResult
    {
        // Validate participant exists
        if (!$this->participantRepository->exists($command->participantId)) {
            throw new EntityNotFoundException('Participant not found');
        }

        $participantId = new ParticipantId($command->participantId);
        $eventId = new EventId($command->eventId);

        // Check for duplicate prediction
        if ($this->predictionRepository->exists($participantId, $eventId)) {
            throw new DuplicatePredictionException('Prediction already exists for this event');
        }

        // Get event deadline
        $deadline = $this->eventRepository->getDeadline($command->eventId);
        if ($deadline === null) {
            throw new EntityNotFoundException('Event not found');
        }

        // Create prediction
        $predictionId = $this->predictionRepository->nextIdentity();
        $prediction = Prediction::submit(
            $predictionId,
            $participantId,
            $eventId,
            new PredictionData($command->predictionData),
            $deadline
        );

        // Save
        $this->predictionRepository->save($prediction);

        return new CommandResult(
            commandId: $command->correlationId ?? uniqid('cmd_', true),
            status: 'accepted',
            resourceId: $predictionId
        );
    }
}
