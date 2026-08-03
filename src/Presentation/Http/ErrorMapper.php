<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\ConcurrencyException;
use BettingGame\Domain\Exception\DuplicateEntryException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Exception\InvalidArgumentException;
use BettingGame\Domain\Exception\UnauthorizedAccessException;
use BettingGame\Support\SchemaOutOfDateException;
use Throwable;

/**
 * Turns an exception into the status code the API documents.
 *
 * The handlers throw domain exceptions and never mention HTTP; this is the one
 * place that knows the translation. Keeping it in a single class is what makes
 * "a rejected business rule is a 409" true everywhere instead of per endpoint.
 *
 * Anything unrecognised is a 500 whose `message` is the same sentence every
 * time, because an unexpected exception can carry connection strings or SQL.
 * What it actually said goes into `detail`, and only in debug mode - a field
 * no interface prints. Putting it in `message` is how a driver error in
 * English ended up in front of a participant.
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

            // A unique key rejecting a write is a business rule saying no, not
            // a database malfunction - but the driver's message names the key,
            // its columns and the values that collided, so it is replaced by
            // the rule in words. The original stays on the exception, which is
            // what the log records.
            $e instanceof DuplicateEntryException => JsonResponse::conflict(
                ConstraintMessages::of($e->constraint())
            ),

            $e instanceof BusinessRuleViolationException => JsonResponse::conflict($e->getMessage()),

            // Our fault, but a nameable one: the database is behind the code.
            // Its own sentence rather than "Internal Server Error", because it
            // is the one 500 somebody can do something about - and it names a
            // column, never a query.
            $e instanceof SchemaOutOfDateException => JsonResponse::internalError($e->getMessage()),

            default => $this->unexpected($e),
        };
    }

    /**
     * A 500 that says nothing but "Internal Server Error", which the catalogue
     * knows and can therefore answer in the caller's language.
     *
     * The exception's own words are a debugging aid, so in debug mode they come
     * along as `detail` - untranslated, next to the translated message, and
     * ignored by every client.
     */
    private function unexpected(Throwable $e): JsonResponse
    {
        $response = JsonResponse::internalError();

        return $this->debug ? $response->withData(['detail' => $e->getMessage()]) : $response;
    }
}
