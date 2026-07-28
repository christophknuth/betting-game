<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

/**
 * The base64url encoding JWS uses (RFC 7515 Appendix C).
 *
 * Decoding is strict on purpose. A lenient decoder silently discards
 * characters it does not recognise, which means two different token strings can
 * decode to the same bytes - so a signature checked over one spelling would
 * cover a payload read from another.
 */
final class Base64Url
{
    public static function decode(string $data): ?string
    {
        if ($data === '' || preg_match('/^[A-Za-z0-9_-]+$/', $data) !== 1) {
            return null;
        }

        $remainder = strlen($data) % 4;

        // A length of 4n+1 cannot be the result of any encoding.
        if ($remainder === 1) {
            return null;
        }

        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
