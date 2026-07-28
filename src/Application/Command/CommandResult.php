<?php

declare(strict_types=1);

namespace BettingGame\Application\Command;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

/**
 * What a command handler hands back.
 *
 * The API answers commands with `202 Accepted` and this body. `commandId` is
 * meant to double as `EventStore.causation_id` so a caller can trace what a
 * command produced - that link is not wired yet and arrives with the command
 * log (OPS-01/OPS-02); until then the id identifies the response, nothing more.
 */
final class CommandResult
{
    public function __construct(
        public readonly string $commandId,
        public readonly string $status,
        public readonly ?int $resourceId = null,
        public readonly ?string $message = null
    ) {
    }

    public static function accepted(?int $resourceId = null, ?string $message = null): self
    {
        return new self(Uuid::uuid4()->toString(), 'accepted', $resourceId, $message);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'commandId' => $this->commandId,
            'status' => $this->status,
            'message' => $this->message,
            'resourceId' => $this->resourceId,
            'timestamp' => (new DateTimeImmutable())->format('c'),
        ];
    }
}
