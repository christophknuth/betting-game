<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Repository\PredictionRepositoryInterface;
use BettingGame\Domain\Repository\GameEventRepositoryInterface;
use BettingGame\Domain\ValueObject\PredictionData;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Exception\UnauthorizedAccessException;

final class UpdatePredictionHandler
{
    public function __construct(
        private PredictionRepositoryInterface $predictionRepository,
        private GameEventRepositoryInterface $eventRepository
    ) {
    }

    public function handle(UpdatePredictionCommand $command): CommandResult
    {
        $prediction = $this->predictionRepository->findById($command->predictionId);
        
        if ($prediction === null) {
            throw new EntityNotFoundException('Prediction not found');
        }

        // Verify ownership
        if ($prediction->participantId()->value() !== $command->participantId) {
            throw new UnauthorizedAccessException('Cannot update another participant\'s prediction');
        }

        // Get deadline
        $deadline = $this->eventRepository->getDeadline($prediction->eventId()->value());
        if ($deadline === null) {
            throw new EntityNotFoundException('Event not found');
        }

        // Update prediction
        $prediction->update(
            new PredictionData($command->predictionData),
            $deadline
        );

        // Save
        $this->predictionRepository->save($prediction);

        return new CommandResult(
            commandId: $command->correlationId ?? uniqid('cmd_', true),
            status: 'accepted',
            resourceId: $command->predictionId
        );
    }
}
