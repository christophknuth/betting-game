<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

/**
 * A JSON Web Key Set (RFC 7517), reduced to the keys we can actually verify
 * with.
 *
 * Only RSA signing keys are kept. Keycloak publishes its RSA-OAEP encryption
 * key in the same document, and a realm may be configured for EC as well; both
 * are skipped rather than rejected, because an entry we cannot use says nothing
 * about the entries we can. What we cannot verify with, we refuse at the point
 * of use - not by quietly accepting it.
 */
final class JwkSet
{
    /** @param array<string, VerificationKey> $keys keyed by kid */
    private function __construct(private array $keys)
    {
    }

    /** @param list<VerificationKey> $keys */
    public static function of(array $keys): self
    {
        $byKid = [];
        foreach ($keys as $key) {
            $byKid[$key->kid] = $key;
        }

        return new self($byKid);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * An unreadable document yields an empty set, never an "accept anything"
     * one. Whether empty is fatal is the caller's decision.
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            return self::empty();
        }

        $keys = [];
        foreach ($decoded['keys'] as $entry) {
            $key = is_array($entry) ? self::key($entry) : null;

            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return self::of($keys);
    }

    /** @param array<array-key, mixed> $jwk */
    private static function key(array $jwk): ?VerificationKey
    {
        if (($jwk['kty'] ?? null) !== 'RSA') {
            return null;
        }

        // "use" is optional, but when it is present and says encryption, the
        // publisher has told us this key never signs anything.
        $use = $jwk['use'] ?? null;
        if (is_string($use) && $use !== 'sig') {
            return null;
        }

        $kid = $jwk['kid'] ?? null;
        $modulus = $jwk['n'] ?? null;
        $exponent = $jwk['e'] ?? null;

        if (!is_string($kid) || !is_string($modulus) || !is_string($exponent)) {
            return null;
        }

        $n = Base64Url::decode($modulus);
        $e = Base64Url::decode($exponent);

        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }

        $algorithm = $jwk['alg'] ?? null;

        return new VerificationKey(
            kid: $kid,
            pem: self::rsaPem($n, $e),
            algorithm: is_string($algorithm) ? $algorithm : null
        );
    }

    /**
     * The key to check a signature with.
     *
     * A kid always wins. Without one we fall back to the single key in the set,
     * which is unambiguous - but never to "try them all", because that turns
     * every key in the set into a way in and hides a rotation gone wrong.
     */
    public function keyFor(?string $kid, string $algorithm): ?VerificationKey
    {
        $key = null;

        if ($kid !== null) {
            $key = $this->keys[$kid] ?? null;
        } elseif (count($this->keys) === 1) {
            $key = reset($this->keys) ?: null;
        }

        if ($key === null) {
            return null;
        }

        if ($key->algorithm !== null && $key->algorithm !== $algorithm) {
            return null;
        }

        return $key;
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    /** @return list<string> */
    public function kids(): array
    {
        return array_keys($this->keys);
    }

    /**
     * Wraps the RSA modulus and exponent in the SubjectPublicKeyInfo structure
     * openssl_verify() expects:
     *
     *     SEQUENCE {
     *       SEQUENCE { OID rsaEncryption, NULL }
     *       BIT STRING { SEQUENCE { INTEGER n, INTEGER e } }
     *     }
     *
     * This is encoding, not cryptography - the verification itself stays with
     * OpenSSL. It is here because a JWKS gives the numbers raw and PHP has no
     * function that takes them.
     */
    private static function rsaPem(string $n, string $e): string
    {
        $rsaKey = self::der(0x30, self::integer($n) . self::integer($e));

        $algorithm = self::der(
            0x30,
            // 1.2.840.113549.1.1.1 rsaEncryption, then the NULL parameters
            // PKCS#1 requires for it.
            self::der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . self::der(0x05, '')
        );

        // The leading zero byte is the BIT STRING's count of unused trailing
        // bits, which is always none here.
        $spki = self::der(0x30, $algorithm . self::der(0x03, "\x00" . $rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function der(int $tag, string $value): string
    {
        return chr($tag) . self::length(strlen($value)) . $value;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * DER INTEGERs are signed, so a modulus whose top bit is set needs a
     * leading zero byte or it reads as a negative number.
     */
    private static function integer(string $value): string
    {
        $value = ltrim($value, "\x00");

        if ($value === '') {
            $value = "\x00";
        }

        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }

        return self::der(0x02, $value);
    }
}
