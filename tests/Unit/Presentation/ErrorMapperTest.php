<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Presentation;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Exception\ConcurrencyException;
use BettingGame\Domain\Exception\DuplicateEntryException;
use BettingGame\Domain\Exception\EntityNotFoundException;
use BettingGame\Domain\Exception\InvalidArgumentException;
use BettingGame\Domain\Exception\UnauthorizedAccessException;
use BettingGame\Presentation\Http\ErrorMapper;
use BettingGame\Presentation\Http\InvalidInputException;
use BettingGame\Presentation\Http\Translator;
use BettingGame\Support\SchemaOutOfDateException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which status an exception becomes, and how much of it the caller is told.
 *
 * The second half is what this test exists for. An unexpected exception carries
 * whatever the thing that failed had to say - a query, a DSN, a driver message
 * in English - and that used to be the response's `message` in debug builds,
 * which is how `SQLSTATE[42S22]: Column not found` came to be read by somebody
 * looking at their own tickets.
 */
final class ErrorMapperTest extends TestCase
{
    private ErrorMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ErrorMapper(debug: false);
    }

    // --- Which status ---

    public function testAccessIsRefusedBeforeAnythingElseIsConsidered(): void
    {
        $response = $this->mapper->toResponse(new UnauthorizedAccessException('Admin access required'));

        self::assertSame(403, $response->statusCode());
        self::assertSame('Admin access required', $response->data()['message']);
    }

    public function testSomethingThatDoesNotExistIsA404(): void
    {
        self::assertSame(
            404,
            $this->mapper->toResponse(new EntityNotFoundException('Draw 9 does not exist'))->statusCode()
        );
    }

    public function testMalformedInputIsA400WhereverItWasNoticed(): void
    {
        self::assertSame(
            400,
            $this->mapper->toResponse(new InvalidInputException('drawId must be an integer'))->statusCode()
        );
        self::assertSame(
            400,
            $this->mapper->toResponse(new InvalidArgumentException('Numbers must be distinct'))->statusCode()
        );
    }

    public function testALostRaceIsAConflictRatherThanAServerFault(): void
    {
        self::assertSame(
            409,
            $this->mapper->toResponse(new ConcurrencyException('Version 3 is stale'))->statusCode()
        );
    }

    public function testARejectedUniqueKeyIsTheRuleInWordsAndNotTheDriverMessage(): void
    {
        $response = $this->mapper->toResponse(new DuplicateEntryException(
            "SQLSTATE[23000]: Duplicate entry '7-3' for key 'uk_participant_period'",
            'uk_participant_period'
        ));

        self::assertSame(409, $response->statusCode());
        self::assertSame(
            'This participant already has a bet row for this period',
            $response->data()['message'],
            'the key, its columns and the values that collided are not the caller\'s business'
        );
    }

    public function testABrokenRuleIsA409InItsOwnWords(): void
    {
        $response = $this->mapper->toResponse(
            new BusinessRuleViolationException('Another tipp year is already running')
        );

        self::assertSame(409, $response->statusCode());
        self::assertSame('Another tipp year is already running', $response->data()['message']);
    }

    // --- How much the caller is told ---

    public function testAnUnexpectedExceptionSaysNothingButThatItFailed(): void
    {
        $response = $this->mapper->toResponse(
            new RuntimeException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 't.duration_weeks'")
        );

        self::assertSame(500, $response->statusCode());
        self::assertSame('Internal Server Error', $response->data()['message']);
        self::assertArrayNotHasKey('detail', $response->data());
    }

    public function testDebugModeAddsTheDetailBesideTheMessageAndNotInsteadOfIt(): void
    {
        $mapper = new ErrorMapper(debug: true);

        $response = $mapper->toResponse(new RuntimeException('Connection refused for user root@db'));

        self::assertSame(
            'Internal Server Error',
            $response->data()['message'],
            'the message stays the sentence the catalogue can translate'
        );
        self::assertSame('Connection refused for user root@db', $response->data()['detail']);
    }

    public function testTheMessageOfAnUnexpectedExceptionCanBeSaidInGerman(): void
    {
        $response = $this->mapper->toResponse(new RuntimeException('anything at all'));

        self::assertSame(
            'Interner Serverfehler',
            Translator::localise($response, 'de')->data()['message']
        );
    }

    // --- The one 500 that names its cause ---

    public function testAnOutOfDateDatabaseSaysSoEvenWithoutDebug(): void
    {
        $response = $this->mapper->toResponse(SchemaOutOfDateException::missingColumn('duration_weeks'));

        self::assertSame(500, $response->statusCode());
        self::assertSame(
            'The database is not up to date with the application: column duration_weeks is missing',
            $response->data()['message']
        );
    }

    public function testAndSaysItInGermanToAGermanBrowser(): void
    {
        $response = $this->mapper->toResponse(SchemaOutOfDateException::missingColumn('duration_weeks'));

        self::assertSame(
            'Die Datenbank ist nicht auf dem Stand der Anwendung: Die Spalte duration_weeks fehlt. '
            . 'Bitte die ausstehenden Migrationen einspielen.',
            Translator::localise($response, 'de')->data()['message']
        );
    }

    public function testEverySentenceAnOutOfDateDatabaseProducesIsInTheCatalogue(): void
    {
        $sentences = [
            SchemaOutOfDateException::missingColumn('a_column')->getMessage(),
            SchemaOutOfDateException::missingTable('a_table')->getMessage(),
            SchemaOutOfDateException::missingField('a_field')->getMessage(),
        ];

        foreach ($sentences as $sentence) {
            self::assertNotSame(
                $sentence,
                Translator::translate($sentence, 'de'),
                "\"$sentence\" has no German translation"
            );
        }
    }
}
