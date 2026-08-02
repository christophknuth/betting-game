<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Presentation;

use BettingGame\Presentation\Http\ConstraintMessages;
use BettingGame\Presentation\Http\GermanMessages;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Errors in the caller's language, English where there is no translation.
 *
 * Two things carry the risk here. The catalogue is keyed by the English
 * message, so a message that fills in a number or a name has to be recognised
 * as the same message - and the values have to survive into the translation
 * unharmed. And the negotiation has to read a real `Accept-Language`, which is
 * a weighted list rather than one tag.
 */
final class TranslatorTest extends TestCase
{
    // --- Which language ---

    public function testTheHighestWeightedLanguageWins(): void
    {
        self::assertSame('de', Translator::preferredLanguage('en;q=0.8,de;q=0.9'));
    }

    public function testARegionIsServedByItsLanguage(): void
    {
        // de-AT and de-CH read the same German catalogue
        self::assertSame('de', Translator::preferredLanguage('de-AT'));
    }

    public function testTheHeaderOrderBreaksATie(): void
    {
        self::assertSame('de', Translator::preferredLanguage('de,en'));
        self::assertSame('en', Translator::preferredLanguage('en,de'));
    }

    public function testWhatABrowserActuallySends(): void
    {
        self::assertSame('de', Translator::preferredLanguage('de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7'));
    }

    public function testALanguageWithoutACatalogueFallsThroughToOneWithout(): void
    {
        // French first, then German: French has no catalogue, so German wins
        self::assertSame('de', Translator::preferredLanguage('fr;q=1.0,de;q=0.5'));
    }

    public function testAnUnknownLanguageFallsBackToEnglish(): void
    {
        self::assertSame('en', Translator::preferredLanguage('fr-FR,fr;q=0.9'));
    }

    public function testNoHeaderIsEnglish(): void
    {
        self::assertSame('en', Translator::preferredLanguage(null));
        self::assertSame('en', Translator::preferredLanguage(''));
    }

    public function testALanguageRejectedWithQZeroIsNotChosen(): void
    {
        self::assertSame('en', Translator::preferredLanguage('de;q=0'));
    }

    // --- Which words ---

    public function testAKnownMessageIsTranslated(): void
    {
        self::assertSame(
            'Die Zahlen müssen verschieden sein',
            Translator::translate('Numbers must be distinct', 'de')
        );
    }

    public function testAnUnknownMessageStaysEnglish(): void
    {
        $message = 'Something no catalogue has ever heard of';

        self::assertSame($message, Translator::translate($message, 'de'));
    }

    public function testEnglishIsNotTranslated(): void
    {
        self::assertSame(
            'Numbers must be distinct',
            Translator::translate('Numbers must be distinct', 'en')
        );
    }

    public function testTheFilledInNumbersSurvive(): void
    {
        self::assertSame(
            'Das Tippjahr 42 gibt es nicht',
            Translator::translate('Tipp year 42 does not exist', 'de')
        );
    }

    public function testATemplateWithSeveralValuesKeepsTheirOrder(): void
    {
        self::assertSame(
            'Eine Tippreihe braucht genau 6 Zahlen, angegeben wurden 4',
            Translator::translate('A bet row needs exactly 6 numbers, got 4', 'de')
        );
    }

    public function testAValueThatIsATextSurvives(): void
    {
        self::assertSame(
            'Die Tippperiode Q1 2027 überschneidet sich mit der bestehenden Periode Q1',
            Translator::translate('The period Q1 2027 overlaps the existing period Q1', 'de')
        );
    }

    /**
     * `%s must be an integer` would match this too, and answer with a sentence
     * that has lost the words "in the path".
     */
    public function testTheMoreSpecificTemplateWins(): void
    {
        self::assertSame(
            'drawId im Pfad muss eine ganze Zahl sein',
            Translator::translate('drawId in the path must be an integer', 'de')
        );
    }

    public function testAnExactEntryBeatsAPatternThatWouldAlsoMatch(): void
    {
        self::assertSame(
            'Jede Gewinnklasse muss ein Objekt sein',
            Translator::translate('Each winning class must be an object', 'de')
        );
    }

    public function testTheDuplicateBetRowThatStartedThisIsGerman(): void
    {
        $english = 'This participant already has a row for this bet period. '
            . 'Supply replaceReason to correct it within the running period.';

        self::assertStringStartsWith(
            'Dieser Teilnehmer hat für diese Tippperiode bereits eine Reihe.',
            Translator::translate($english, 'de')
        );
    }

    /**
     * Every sentence a rejected unique key can produce is one a caller reads,
     * so none of them may be left without a translation.
     */
    public function testEveryConstraintSentenceIsInTheCatalogue(): void
    {
        $sentences = [...array_values(ConstraintMessages::MESSAGES), ConstraintMessages::of('nope')];

        foreach ($sentences as $sentence) {
            self::assertArrayHasKey(
                $sentence,
                GermanMessages::MESSAGES,
                "\"$sentence\" has no German translation"
            );
        }
    }

    // --- Which responses ---

    public function testOnlyAnErrorIsTranslated(): void
    {
        // 202 carries a report of what a command did, not something to act on
        $accepted = JsonResponse::accepted(['message' => 'Numbers must be distinct']);

        self::assertSame(
            'Numbers must be distinct',
            Translator::localise($accepted, 'de')->data()['message']
        );
    }

    public function testAnErrorResponseIsTranslatedInPlace(): void
    {
        $conflict = JsonResponse::conflict('Another tipp year is already running');
        $localised = Translator::localise($conflict, 'de');

        self::assertSame('Es läuft bereits ein anderes Tippjahr', $localised->data()['message']);
        self::assertSame(409, $localised->statusCode(), 'the status is not the message');
        self::assertSame('Conflict', $localised->data()['error'], 'nor is the error type');
    }

    public function testAResponseWithoutAMessageIsLeftAlone(): void
    {
        $response = JsonResponse::of(404, ['error' => 'Not Found']);

        self::assertSame(['error' => 'Not Found'], Translator::localise($response, 'de')->data());
    }

    // --- The catalogue itself ---

    public function testNoTranslationLostAPlaceholder(): void
    {
        foreach (GermanMessages::MESSAGES as $english => $german) {
            self::assertSame(
                substr_count($english, '%'),
                substr_count($german, '%'),
                "The translation of \"$english\" does not fill in as many values as the original"
            );
        }
    }

    public function testNoTranslationIsStillTheEnglishSentence(): void
    {
        foreach (GermanMessages::MESSAGES as $english => $german) {
            self::assertNotSame($english, $german, "\"$english\" is not translated");
        }
    }
}
