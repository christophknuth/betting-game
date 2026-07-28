<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Query\GetCommandStatusHandler;
use BettingGame\Application\Query\GetCommandStatusQuery;
use BettingGame\Presentation\Http\InvalidInputException;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

/**
 * OPS-01: `GET /commands/{commandId}`.
 *
 * Not admin-only - the caller who issued a command is entitled to find out
 * what became of it, and the id is unguessable.
 */
final class CommandStatusController
{
    public function __construct(
        private GetCommandStatusHandler $commandStatus
    ) {
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): JsonResponse
    {
        $commandId = $params['commandId'] ?? '';

        if (preg_match('/^[0-9a-fA-F-]{36}$/', $commandId) !== 1) {
            throw new InvalidInputException('commandId must be a UUID');
        }

        return JsonResponse::ok(
            $this->commandStatus->handle(new GetCommandStatusQuery($commandId))->toArray()
        );
    }
}
