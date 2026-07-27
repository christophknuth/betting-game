<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Controller;

use BettingGame\Application\Command\ApproveParticipantCommand;
use BettingGame\Application\Command\ApproveParticipantHandler;
use BettingGame\Application\Command\CreateParticipantCommand;
use BettingGame\Application\Command\CreateParticipantHandler;
use BettingGame\Application\Query\GetAllParticipantsHandler;
use BettingGame\Application\Query\GetAllParticipantsQuery;
use BettingGame\Application\Query\GetPendingParticipantsHandler;
use BettingGame\Application\Query\GetPendingParticipantsQuery;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\DomainException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Presentation\Http\Input;
use BettingGame\Presentation\Http\InvalidInputException;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Request;

final class AdminParticipantController
{
    public function __construct(
        private CreateParticipantHandler $createParticipantHandler,
        private ApproveParticipantHandler $approveParticipantHandler,
        private GetAllParticipantsHandler $getAllParticipantsHandler,
        private GetPendingParticipantsHandler $getPendingParticipantsHandler
    ) {
    }

    /** @param array<string, string> $params */
    public function getAllParticipants(Request $request, array $params): JsonResponse
    {
        $bettingGameId = $request->queryParam('bettingGameId');

        $query = new GetAllParticipantsQuery(
            status: $request->queryParam('status'),
            bettingGameId: $bettingGameId !== null ? (int) $bettingGameId : null
        );

        try {
            $result = $this->getAllParticipantsHandler->handle($query);
            return JsonResponse::ok($result->data());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    /** @param array<string, string> $params */
    public function getPendingParticipants(Request $request, array $params): JsonResponse
    {
        $query = new GetPendingParticipantsQuery((int) $params['bettingGameId']);

        try {
            $result = $this->getPendingParticipantsHandler->handle($query);
            return JsonResponse::ok($result->data());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    /** @param array<string, string> $params */
    public function createParticipant(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        try {
            // userId is still mandatory here: guest participants without a user
            // account are specified in the API but not yet supported by the command.
            $command = new CreateParticipantCommand(
                userId: Input::int($body, 'userId'),
                displayName: Input::string($body, 'displayName'),
                autoApprove: Input::bool($body, 'autoApprove', false),
                correlationId: $request->header('X-Correlation-ID')
            );
        } catch (InvalidInputException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }

        try {
            $result = $this->createParticipantHandler->handle($command);
            return JsonResponse::accepted($result->toArray());
        } catch (DomainException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }
    }

    /** @param array<string, string> $params */
    public function approveParticipant(Request $request, array $params): JsonResponse
    {
        $body = $request->jsonBody();

        if (!array_key_exists('approved', $body)) {
            return JsonResponse::badRequest('approved is required');
        }

        try {
            $command = new ApproveParticipantCommand(
                participantId: (int) $params['participantId'],
                approved: Input::bool($body, 'approved', false),
                bettingGameId: Input::optionalInt($body, 'bettingGameId'),
                notes: Input::optionalString($body, 'notes'),
                correlationId: $request->header('X-Correlation-ID')
            );
        } catch (InvalidInputException $e) {
            return JsonResponse::badRequest($e->getMessage());
        }

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
