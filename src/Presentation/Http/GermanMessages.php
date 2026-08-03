<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

/**
 * Every error message this API can put in front of a caller, in German.
 *
 * Keyed by the English original, `%s` and `%d` marking the parts a message
 * fills in at the moment it is thrown. What is missing here comes back in
 * English - see Translator.
 *
 * **Order matters where one template can match another's message.** Literal
 * entries are looked up first and always win; among the patterns the first
 * match is taken, so the specific one has to come before the general one -
 * `%s in the path must be an integer` before `%s must be an integer`.
 *
 * **The filled-in values are not translated.** They are names, dates, numbers
 * and - in a few places - a status as the API spells it (`running`, `paid`).
 * Translating captured text would mean guessing whether a value is a term of
 * this domain or somebody's name, and getting that wrong renames a person. The
 * German sentences are worded so the raw term reads as the code it is.
 */
final class GermanMessages
{
    /** @var array<string, string> */
    public const MESSAGES = [
        // --- The six numbers, the Superzahl, the winning classes -------------
        'Numbers must be integers' => 'Die Zahlen müssen ganze Zahlen sein',
        'Numbers must be distinct' => 'Die Zahlen müssen verschieden sein',
        'A bet row needs exactly %d numbers, got %d'
            => 'Eine Tippreihe braucht genau %d Zahlen, angegeben wurden %d',
        'Number %d is outside %d-%d' => 'Die Zahl %d liegt außerhalb von %d–%d',
        'Superzahl %d is outside %d-%d' => 'Die Superzahl %d liegt außerhalb von %d–%d',
        'Winning class %d is outside %d-%d' => 'Die Gewinnklasse %d liegt außerhalb von %d–%d',
        'Matched numbers out of range: %d' => 'Ungültige Anzahl Treffer: %d',
        'The new numbers are identical to the current ones'
            => 'Die neuen Zahlen sind mit den bisherigen identisch',

        // B-06 checks for the existing row itself rather than letting
        // uk_participant_period fire, so this is the sentence a second row for
        // the same period actually produces - and the one that started all this.
        'This participant already has a row for this bet period. '
        . 'Supply replaceReason to correct it within the running period.'
            => 'Dieser Teilnehmer hat für diese Tippperiode bereits eine Reihe. '
                . 'Zum Ersetzen innerhalb der laufenden Periode ist ein Grund nötig '
                . '(Feld replaceReason).',

        // --- Participants ---------------------------------------------------
        'Display name cannot be empty' => 'Der Anzeigename darf nicht leer sein',
        'Display name must be at least 2 characters'
            => 'Der Anzeigename muss mindestens 2 Zeichen lang sein',
        'Display name cannot exceed 50 characters'
            => 'Der Anzeigename darf höchstens 50 Zeichen lang sein',
        'Invalid email address' => 'Ungültige E-Mail-Adresse',
        'Participant ID must be positive' => 'Die Teilnehmer-ID muss positiv sein',
        'Participant is already active' => 'Dieser Teilnehmer ist bereits aktiv',
        'The new display name is identical to the current one'
            => 'Der neue Anzeigename ist mit dem bisherigen identisch',
        'This participant is already active' => 'Dieser Teilnehmer ist bereits aktiv',
        'This participant is already inactive' => 'Dieser Teilnehmer ist bereits inaktiv',
        'Participant %d is inactive and cannot join a tipp year'
            => 'Der Teilnehmer %d ist inaktiv und kann keinem Tippjahr beitreten',

        // --- Tipp year, periods, tickets ------------------------------------
        'Cost per row must be positive' => 'Der Preis je Reihe muss positiv sein',
        'A processing fee cannot be negative' => 'Das Bearbeitungsentgelt darf nicht negativ sein',
        'End date must be after start date' => 'Das Ende muss nach dem Beginn liegen',
        'End date must not be before start date' => 'Das Ende darf nicht vor dem Beginn liegen',
        'A bet period needs a name' => 'Eine Tippperiode braucht einen Namen',
        'The period %s is not inside the tipp year %s'
            => 'Die Tippperiode %s liegt nicht innerhalb des Tippjahres %s',
        'The period %s overlaps the existing period %s'
            => 'Die Tippperiode %s überschneidet sich mit der bestehenden Periode %s',
        'The tipp year %s overlaps the existing tipp year %s'
            => 'Das Tippjahr %s überschneidet sich mit dem bestehenden Tippjahr %s',
        'A ticket needs at least one bet row' => 'Ein Tippschein braucht mindestens eine Tippreihe',
        'A ticket runs for at least one week' => 'Ein Tippschein läuft mindestens eine Woche',
        'A ticket runs for at most %d weeks' => 'Ein Tippschein läuft höchstens %d Wochen',
        'Draw days must be one of %s, got %s'
            => 'Die Ziehungstage müssen %s sein, angegeben wurde %s',
        'A ticket can only be submitted while the tipp year runs, it is %s'
            => 'Ein Tippschein lässt sich nur bei laufendem Tippjahr erfassen; dieses steht auf „%s"',
        'Cannot add members to a distributed tipp year'
            => 'Zu einem ausgeschütteten Tippjahr lassen sich keine Teilnehmer mehr aufnehmen',
        'This tipp year is already %s' => 'Dieses Tippjahr steht bereits auf „%s"',
        'Unknown tipp year status: %s' => 'Unbekannter Status eines Tippjahres: %s',

        // --- Draws and winnings ---------------------------------------------
        'The draw has no numbers yet' => 'Für diese Ziehung sind noch keine Zahlen eingetragen',
        'The draw has not been recorded yet' => 'Diese Ziehung ist noch nicht eingetragen',
        'A winning amount cannot be negative' => 'Ein Gewinnbetrag darf nicht negativ sein',
        'The draw date %s is outside the tipp year %s'
            => 'Das Ziehungsdatum %s liegt außerhalb des Tippjahres %s',
        'No ticket covers the draw of %s' => 'Kein Tippschein umfasst die Ziehung vom %s',
        'Record either the ticket total or the amounts per winning class'
            => 'Entweder den Gesamtgewinn des Scheins oder die Beträge je Gewinnklasse angeben',
        'The ticket total follows from the amounts per winning class and is not recorded with them'
            => 'Der Gesamtgewinn ergibt sich aus den Beträgen je Gewinnklasse und wird nicht '
                . 'zusätzlich angegeben',
        'Winning class %d is listed twice' => 'Die Gewinnklasse %d ist doppelt angegeben',

        // --- Fees and the distribution --------------------------------------
        'A fee must be a positive amount' => 'Eine Gebühr muss ein positiver Betrag sein',
        'Waiving a fee requires a reason' => 'Der Erlass einer Gebühr braucht einen Grund',
        'This fee is already %s' => 'Diese Gebühr steht bereits auf „%s"',
        'This fee is %s and cannot be reopened'
            => 'Diese Gebühr steht auf „%s" und lässt sich nicht wieder öffnen',
        'Only a closed tipp year can be distributed'
            => 'Ausgeschüttet wird nur aus einem abgeschlossenen Tippjahr',
        'This tipp year has already been distributed' => 'Dieses Tippjahr ist bereits ausgeschüttet',
        'This tipp year has no members to distribute to'
            => 'Dieses Tippjahr hat keine Teilnehmer, an die ausgeschüttet werden könnte',
        'A distribution needs at least one participant'
            => 'Eine Ausschüttung braucht mindestens einen Teilnehmer',
        'An amount cannot be split into fewer than one share'
            => 'Ein Betrag lässt sich nicht auf weniger als einen Anteil aufteilen',

        // --- Nothing of that name -------------------------------------------
        'No such tipp year' => 'Dieses Tippjahr gibt es nicht',
        'Tipp year %d does not exist' => 'Das Tippjahr %d gibt es nicht',
        'Bet period %d does not exist' => 'Die Tippperiode %d gibt es nicht',
        'Participant %d does not exist' => 'Den Teilnehmer %d gibt es nicht',
        'Draw %d does not exist' => 'Die Ziehung %d gibt es nicht',
        'Fee %d does not exist' => 'Die Gebühr %d gibt es nicht',
        'Command %s is unknown' => 'Das Kommando %s ist unbekannt',
        'No bet period covers %s' => 'Keine Tippperiode umfasst den %s',
        'No tipp year covers %s' => 'Kein Tippjahr umfasst den %s',
        'No event history for %s' => 'Für %s gibt es keine Ereignishistorie',
        'There is no projection called %s' => 'Es gibt keine Projektion namens %s',

        // --- Who is asking ---------------------------------------------------
        'You may only access your own data' => 'Zugriff besteht nur auf die eigenen Daten',
        'Admin access required' => 'Dafür sind Administratorrechte nötig',
        'Insufficient permissions' => 'Dafür fehlt die Berechtigung',
        'No participant is associated with this token'
            => 'Zu diesem Zugang ist kein Teilnehmer hinterlegt',
        'No authorization token provided' => 'Es wurde kein Token mitgesendet',
        'Invalid authorization header format'
            => 'Der Authorization-Header hat ein ungültiges Format',
        'Invalid or expired token' => 'Das Token ist ungültig oder abgelaufen',
        'Authentication is temporarily unavailable'
            => 'Die Anmeldung ist vorübergehend nicht erreichbar',
        'Unauthorized' => 'Nicht angemeldet',
        'Forbidden' => 'Nicht erlaubt',
        'Not Found' => 'Nicht gefunden',
        'Internal Server Error' => 'Interner Serverfehler',
        'Service Unavailable' => 'Dienst nicht verfügbar',

        // --- A request that never reached a rule ------------------------------
        'A command was rejected without an idempotency key'
            => 'Ein Kommando wurde ohne Idempotenzschlüssel abgelehnt',
        'commandId must be a UUID' => 'Die commandId muss eine UUID sein',
        'winningClasses must be an array' => 'winningClasses muss eine Liste sein',
        'Each winning class must be an object' => 'Jede Gewinnklasse muss ein Objekt sein',
        'Allowed: %s' => 'Erlaubt: %s',

        // The specific one first - "%s must be an integer" would match this too
        '%s in the path must be an integer' => '%s im Pfad muss eine ganze Zahl sein',
        '%s must be an array of integers' => '%s muss eine Liste ganzer Zahlen sein',
        '%s must contain integers only' => '%s darf nur ganze Zahlen enthalten',
        '%s must be an integer' => '%s muss eine ganze Zahl sein',
        // Before the general one, which would otherwise match this too and
        // translate only its second half
        '%s is required and must be a boolean'
            => '%s muss angegeben werden und ein Wahrheitswert sein',
        '%s must be a boolean' => '%s muss ein Wahrheitswert sein',
        '%s must be a non-empty string' => '%s darf nicht leer sein',
        '%s must be a number' => '%s muss eine Zahl sein',
        '%s must be an object' => '%s muss ein Objekt sein',

        // --- A unique key said no (see ConstraintMessages) --------------------
        'This participant already has a bet row for this period'
            => 'Dieser Teilnehmer hat für diese Tippperiode bereits eine Tippreihe',
        'A draw has already been recorded for this date'
            => 'Für dieses Datum ist bereits eine Ziehung eingetragen',
        'This participant is already a member of this tipp year'
            => 'Dieser Teilnehmer nimmt an diesem Tippjahr bereits teil',
        'A ticket has already been recorded for this period'
            => 'Für diesen Zeitraum ist bereits ein Tippschein erfasst',
        'This participant has already been charged for this ticket'
            => 'Für diesen Tippschein ist diesem Teilnehmer bereits eine Gebühr berechnet',
        'This bet row is already on this ticket'
            => 'Diese Tippreihe steht bereits auf diesem Tippschein',
        'This participant already has a share of this distribution'
            => 'Dieser Teilnehmer hat bereits einen Anteil an dieser Ausschüttung',
        'A period already starts on this date in this tipp year'
            => 'In diesem Tippjahr beginnt bereits eine Tippperiode an diesem Datum',
        'Another tipp year is already running' => 'Es läuft bereits ein anderes Tippjahr',
        'This account is already linked to a participant'
            => 'Dieses Konto ist bereits einem Teilnehmer zugeordnet',
        'This username is already taken' => 'Dieser Benutzername ist bereits vergeben',
        'This email address is already taken' => 'Diese E-Mail-Adresse ist bereits vergeben',
        'This bet row has already been evaluated for this draw'
            => 'Diese Tippreihe ist für diese Ziehung bereits ausgewertet',
        'The winnings of this ticket have already been recorded for this draw'
            => 'Der Gewinn dieses Tippscheins ist für diese Ziehung bereits eingetragen',
        'This command has already been sent with the same idempotency key'
            => 'Dieses Kommando wurde mit demselben Idempotenzschlüssel bereits gesendet',
        'This entry already exists' => 'Diesen Eintrag gibt es bereits',
    ];
}
