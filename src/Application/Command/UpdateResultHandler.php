<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Repository\ResultRepositoryInterface;
use BettingGame\Domain\Exception\EntityNotFoundException;

final class UpdateResultHandler
{
    public function __construct(
        private ResultRepositoryInterface $resultRepository
    ) {
    }

    public function handle(UpdateResultCommand $command): CommandResult
    {
        $result = $this->resultRepository->findByEventId($command->eventId);
        if ($result === null) {
            throw new EntityNotFoundException('Result not found for this event');
        }

        $result->update($command->resultData, $command->reason);

        $this->resultRepository->save($result);

        return new CommandResult(
            commandId: $command->correlationId ?? uniqid('cmd_', true),
            status: 'accepted',
            resourceId: (string) $result->id(),
            message: 'Result update accepted'
        );
    }
}
