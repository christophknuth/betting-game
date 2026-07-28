<?php

declare(strict_types=1);

namespace BettingGame\Domain\Exception;

/**
 * A unique key rejected a write.
 *
 * Several rules of this domain are enforced by the schema rather than by a
 * check in code - one bet row per participant and period, one draw per date,
 * one fee per participant and ticket. Without this exception the application
 * layer would have to catch PDOException and read SQLSTATE to tell "the rule
 * said no" from "the database is broken".
 *
 * It is a business rule violation, so it maps to 409 like any other.
 */
final class DuplicateEntryException extends BusinessRuleViolationException
{
    public function __construct(
        string $message,
        private string $constraint = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The unique key that rejected the write, e.g. `uk_participant_period`.
     * Empty when the driver did not name one.
     */
    public function constraint(): string
    {
        return $this->constraint;
    }

    public function violated(string $constraint): bool
    {
        return $this->constraint === $constraint;
    }
}
