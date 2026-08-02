<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

/**
 * Says the error in the caller's language, where that is known.
 *
 * The catalogue is keyed by the English message, because that is the identifier
 * this codebase actually has: every rule throws its own sentence, and giving
 * ninety-nine call sites a message key instead would be a refactor of the
 * domain to solve a problem at the edge. What is not in the catalogue comes
 * back in English, which is the documented fallback rather than a gap.
 *
 * Messages carry their variable parts as `sprintf` placeholders. The catalogue
 * entry keeps them, so `Tipp year %d does not exist` matches the message that
 * was actually thrown and the numbers survive into the translation. A German
 * entry may reorder them with `%1$s`, `%2$s`.
 *
 * The translation happens on the way out and nowhere else. The exception, the
 * command log and the container log keep the English wording - whoever reads a
 * log should not have to guess which language a line was written in.
 */
final class Translator
{
    /** What every unknown language falls back to, and the language the code is written in. */
    public const FALLBACK = 'en';

    /** @var array<string, array<string, string>> language => (English message => translation) */
    private const CATALOGUES = ['de' => GermanMessages::MESSAGES];

    /**
     * The best of the languages the caller asked for.
     *
     * `Accept-Language: de-DE,de;q=0.9,en;q=0.8` is a preference list with
     * weights, so it is read as one: highest q first, region stripped
     * (`de-AT` is served by the German catalogue), and the first one with a
     * catalogue wins. Browsers send this by themselves - it is a forbidden
     * header for scripts, so the SPA neither can nor has to set it.
     */
    public static function preferredLanguage(?string $acceptLanguage): string
    {
        if ($acceptLanguage === null || trim($acceptLanguage) === '') {
            return self::FALLBACK;
        }

        $weighted = [];

        foreach (explode(',', $acceptLanguage) as $position => $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(trim($pieces[0]));

            if ($tag === '') {
                continue;
            }

            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/', $parameter, $matches) === 1) {
                    $quality = (float) $matches[1];
                }
            }

            // A q of 0 means "not this one", which is a rejection rather than a
            // weak preference.
            if ($quality > 0.0) {
                // The position breaks ties in the order the header listed them
                $weighted[] = ['tag' => $tag, 'quality' => $quality, 'position' => $position];
            }
        }

        usort(
            $weighted,
            static fn (array $a, array $b): int => $b['quality'] <=> $a['quality']
                ?: $a['position'] <=> $b['position']
        );

        foreach ($weighted as $candidate) {
            $language = explode('-', $candidate['tag'])[0];

            if ($language === self::FALLBACK || isset(self::CATALOGUES[$language])) {
                return $language;
            }
        }

        return self::FALLBACK;
    }

    /**
     * The message in that language, or unchanged when it is not in the catalogue.
     */
    public static function translate(string $message, string $language): string
    {
        $catalogue = self::CATALOGUES[$language] ?? null;

        if ($catalogue === null) {
            return $message;
        }

        if (isset($catalogue[$message])) {
            return $catalogue[$message];
        }

        foreach ($catalogue as $template => $translation) {
            $values = self::match($template, $message);

            if ($values !== null) {
                return vsprintf($translation, $values);
            }
        }

        return $message;
    }

    /**
     * Only the error is translated, and only its message.
     *
     * Below 400 the `message` field is the answer to a command - "Draw
     * recorded, 3 rows of ticket 1 evaluated" - which is a report rather than
     * something the reader is being told to act on. Translating those is a
     * separate decision, and quietly doing it here would be the wrong place to
     * take it.
     */
    public static function localise(JsonResponse $response, string $language): JsonResponse
    {
        $message = $response->data()['message'] ?? null;

        if ($response->statusCode() < 400 || !is_string($message) || $message === '') {
            return $response;
        }

        return $response->withData(['message' => self::translate($message, $language)]);
    }

    /**
     * The values a message filled a template's placeholders with, or null when
     * it is a different message altogether.
     *
     * @return list<string>|null
     */
    private static function match(string $template, string $message): ?array
    {
        if (!str_contains($template, '%')) {
            return null;
        }

        $pattern = preg_quote($template, '/');

        // preg_quote leaves %s and %d alone but escapes the dot of %.2f
        $pattern = str_replace(
            ['%s', '%d', '%\.2f', '%f'],
            ['(.+)', '(-?\d+)', '(-?[\d.]+)', '(-?[\d.]+)'],
            $pattern
        );

        if (preg_match('/^' . $pattern . '$/u', $message, $matches) !== 1) {
            return null;
        }

        return array_values(array_slice($matches, 1));
    }
}
