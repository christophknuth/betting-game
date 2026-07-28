<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\ConcurrencyException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Exception\InvalidArgumentException;
use BettingGame\Domain\Exception\UnauthorizedAccessException;
use Throwable;

/**
 * Turns an exception into the status code the API documents.
 *
 * The handlers throw domain exceptions and never mention HTTP; this is the one
 * place that knows the translation. Keeping it in a single class is what makes
 * "a rejected business rule is a 409" true everywhere instead of per endpoint.
 *
 * Anything unrecognised is a 500 - and its message is only shown in debug mode,
 * because an unexpected exception can carry connection strings or SQL.
 */
final class ErrorMapper
{
    public function __construct(private bool $debug = false)
    {
    }

    public function toResponse(Throwable $e): JsonResponse
    {
        return match (true) {
            // A path the caller may not take, before anything else is considered
            $e instanceof UnauthorizedAccessException => JsonResponse::forbidden($e->getMessage()),

            $e instanceof EntityNotFoundException => JsonResponse::notFound($e->getMessage()),

            // Malformed input, whether it failed at the HTTP edge or in a value object
            $e instanceof InvalidInputException,
            $e instanceof InvalidArgumentException => JsonResponse::badRequest($e->getMessage()),

            // Lost the optimistic-locking race: the caller can retry, so it is a
            // conflict rather than a server fault
            $e instanceof ConcurrencyException => JsonResponse::conflict($e->getMessage()),

            // DuplicateEntryException lands here too - a unique key rejecting a
            // write is a business rule saying no, not a database malfunction
            $e instanceof BusinessRuleViolationException => JsonResponse::conflict($e->getMessage()),

            default => JsonResponse::internalError(
                $this->debug ? $e->getMessage() : 'Internal Server Error'
            ),
        };
    }
}
