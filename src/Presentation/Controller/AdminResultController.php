<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\RecordResultCommand;
use BettingGame\Application\Command\RecordResultHandler;
use BettingGame\Domain\Exception\DomainException;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

final class AdminResultController
{
    public function __construct(
        private RecordResultHandler $recordResultHandler
    ) {
    }

    public function recordResult(Request $request, array $params): JsonResponse
    {
        $eventId = (int) $params['eventId'];
        $body = $request->jsonBody();

        if (!isset($body['resultData'])) {
            return JsonResponse::badRequest('resultData is required');
        }

        $command = new RecordResultCommand(
            eventId: $eventId,
            resultData: $body['resultData'],
            source: $body['source'] ?? null,
            correlationId: $request->header('X-Correlation-ID')
        );

        try {
            $result = $this->recordResultHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }
}
