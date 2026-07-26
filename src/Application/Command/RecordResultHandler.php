<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use BettingGame\Domain\Model\Result;
use BettingGame\Domain\Repository\ResultRepositoryInterface;
use BettingGame\Domain\Repository\GameEventRepositoryInterface;
use BettingGame\Domain\Exception\EntityNotFoundException;

final class RecordResultHandler
{
    public function __construct(
        private ResultRepositoryInterface $resultRepository,
        private GameEventRepositoryInterface $eventRepository
    ) {
    }

    public function handle(RecordResultCommand $command): CommandResult
    {
        $event = $this->eventRepository->findById($command->eventId);
        if ($event === null) {
            throw new EntityNotFoundException('Event not found');
        }

        $resultId = $this->resultRepository->nextIdentity();

        $result = Result::record(
            $resultId,
            $command->eventId,
            $command->resultData,
            $command->source
        );

        $this->resultRepository->save($result);

        return new CommandResult(
            commandId: $command->correlationId ?? uniqid('cmd_', true),
            status: 'accepted',
            resourceId: (string) $resultId,
            message: 'Result recording accepted'
        );
    }
}
