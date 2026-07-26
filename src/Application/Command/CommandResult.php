<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

final class CommandResult
{
    public function __construct(
        public readonly string $commandId,
        public readonly string $status,
        public readonly ?string $resourceId = null,
        public readonly ?string $message = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'commandId' => $this->commandId,
            'status' => $this->status,
            'resourceId' => $this->resourceId,
            'message' => $this->message,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];
    }
}
