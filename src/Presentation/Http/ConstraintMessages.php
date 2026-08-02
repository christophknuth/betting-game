<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

/**
 * What a rejected unique key means, in a sentence.
 *
 * Several rules of this domain are enforced by the schema rather than by a
 * check in code, so the database is where they fire - and what came back was
 * the driver's own words:
 *
 *   SQLSTATE[23000]: Integrity constraint violation: 1062
 *   Duplicate entry '7-3' for key 'uk_participant_period'
 *
 * That went straight into the browser. It is unreadable, it is in the wrong
 * language, and it hands out the schema: the name of the key, the columns that
 * make it up and the values that collided.
 *
 * The raw message is not lost - it stays on the exception and goes to the
 * container's log, where whoever is debugging wants it.
 */
final class ConstraintMessages
{
    /**
     * Keyed by the unique key's name, as MariaDB reports it.
     *
     * Only the keys a caller can actually run into are listed. The internals of
     * event sourcing (`uk_aggregate_version`, `uk_stream_version`) are a
     * concurrency race rather than a rule somebody broke, and they arrive as a
     * ConcurrencyException long before this.
     *
     * @var array<string, string>
     */
    public const MESSAGES = [
        // B-06: one bet row per participant and period
        'uk_participant_period' => 'This participant already has a bet row for this period',

        // B-08: one draw per date
        'uk_draw_date' => 'A draw has already been recorded for this date',

        // B-11: one membership per participant and tipp year
        'uk_participant_year' => 'This participant is already a member of this tipp year',

        // B-12: one ticket per tipp year and period start
        'uk_year_period' => 'A ticket has already been recorded for this period',

        // B-12: one fee per participant and ticket
        'uk_participant_ticket' => 'This participant has already been charged for this ticket',

        // B-12: a bet row appears once on a ticket
        'uk_ticket_bet_row' => 'This bet row is already on this ticket',

        // B-13: one distribution per tipp year
        'uk_tipp_year' => 'This tipp year has already been distributed',

        // B-13: one share per participant and distribution
        'uk_payout_participant' => 'This participant already has a share of this distribution',

        // B-14: one period per tipp year and start date
        'uk_year_start' => 'A period already starts on this date in this tipp year',

        // B-18: at most one running tipp year, through the running_marker column
        'uk_single_running_year' => 'Another tipp year is already running',

        // B-21: a display name is not unique, but the account behind it is
        'uk_user' => 'This account is already linked to a participant',
        'uk_username' => 'This username is already taken',
        'uk_email' => 'This email address is already taken',

        // B-09/B-22: one evaluation per row and draw
        'uk_row_draw' => 'This bet row has already been evaluated for this draw',
        'uk_ticket_draw' => 'The winnings of this ticket have already been recorded for this draw',

        // OPS-02: the idempotency key was claimed by another attempt
        'uk_idempotency_key' => 'This command has already been sent with the same idempotency key',
    ];

    /**
     * The sentence for a constraint, or a general one.
     *
     * A key nobody has written a sentence for still must not leak the driver's
     * message - "already exists" is vague, but it is true, and it says nothing
     * about the schema.
     */
    public static function of(string $constraint): string
    {
        return self::MESSAGES[$constraint] ?? 'This entry already exists';
    }
}
