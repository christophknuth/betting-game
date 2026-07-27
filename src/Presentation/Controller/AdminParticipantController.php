<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\ApproveParticipantCommand;
use BettingGame\Application\Command\ApproveParticipantHandler;
use BettingGame\Application\Command\CreateParticipantCommand;
use BettingGame\Application\Command\CreateParticipantHandler;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\DomainException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

final class AdminParticipantController
{
    public function __construct(
        private CreateParticipantHandler $createParticipantHandler,
        private ApproveParticipantHandler $approveParticipantHandler
    ) {
    }

    public function createParticipant(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        // userId is still mandatory here: guest participants without a user account
        // are specified in the API but not yet supported by CreateParticipantCommand.
        if (!isset($body['userId'], $body['displayName'])) {
            return JsonResponse::badRequest('userId and displayName are required');
        }

        $command = new CreateParticipantCommand(
            userId: (int) $body['userId'],
            displayName: (string) $body['displayName'],
            autoApprove: (bool) ($body['autoApprove'] ?? false),
            correlationId: $request->header('X-Correlation-ID')
        );

        try {
            $result = $this->createParticipantHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    public function approveParticipant(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        if (!array_key_exists('approved', $body)) {
            return JsonResponse::badRequest('approved is required');
        }

        $command = new ApproveParticipantCommand(
            participantId: (int) $params['participantId'],
            approved: (bool) $body['approved'],
            bettingGameId: isset($body['bettingGameId']) ? (int) $body['bettingGameId'] : null,
            notes: $body['notes'] ?? null,
            correlationId: $request->header('X-Correlation-ID')
        );

        try {
            $result = $this->approveParticipantHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (EntityNotFoundException $e) {
            return JsonResponse::notFound($e->getMessage());
        } catch (BusinessRuleViolationException $e) {
            return JsonResponse::conflict($e->getMessage());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }
}
