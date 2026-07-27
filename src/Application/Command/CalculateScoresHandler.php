<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Repository\GameEventRepositoryInterface;
use BettingGame\Domain\Exception\EntityNotFoundException;

final class CalculateScoresHandler
{
    public function __construct(
        private GameEventRepositoryInterface $eventRepository
    ) {
    }

    public function handle(CalculateScoresCommand $command): CommandResult
    {
        $event = $this->eventRepository->findById($command->eventId);
        if ($event === null) {
            throw new EntityNotFoundException('Event not found');
        }

        // Score calculation would be triggered asynchronously in production
        return new CommandResult(
            commandId: $command->correlationId ?? uniqid('cmd_', true),
            status: 'accepted',
            message: 'Score calculation initiated'
        );
    }
}
