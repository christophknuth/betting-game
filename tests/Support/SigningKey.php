<?php

declare(strict_types=1);

namespace BettingGame\Tests\Support;

use BettingGame\Infrastructure\Auth\Base64Url;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * An RSA key pair that stands in for Keycloak's.
 *
 * The tests need tokens that genuinely verify, which means really signing them.
 * A fixture with a hard-coded signature would be a fixture that stops proving
 * anything the moment the verifier changes.
 *
 * Key generation costs about a tenth of a second, so the default pair is made
 * once and shared. Tests that need a second, foreign key ask for one.
 */
final class SigningKey
{
    private const DIGESTS = [
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS384' => OPENSSL_ALGO_SHA384,
        'RS512' => OPENSSL_ALGO_SHA512,
    ];

    private static ?self $shared = null;

    private function __construct(
        private OpenSSLAsymmetricKey $key,
        public readonly string $kid
    ) {
    }

    public static function shared(): self
    {
        return self::$shared ??= self::generate('test-key-1');
    }

    public static function generate(string $kid): self
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if ($key === false) {
            throw new RuntimeException('could not generate an RSA key pair');
        }

        return new self($key, $kid);
    }

    /** The public half, as Keycloak would publish it. */
    public function publicKeyPem(): string
    {
        $details = openssl_pkey_get_details($this->key);

        if ($details === false || !isset($details['key']) || !is_string($details['key'])) {
            throw new RuntimeException('could not read the public key');
        }

        return $details['key'];
    }

    /** @return array<string, string> */
    public function jwk(string $algorithm = 'RS256', string $use = 'sig'): array
    {
        $details = openssl_pkey_get_details($this->key);

        if ($details === false || !isset($details['rsa'])) {
            throw new RuntimeException('could not read the RSA parameters');
        }

        $rsa = $details['rsa'];

        if (!is_array($rsa) || !is_string($rsa['n']) || !is_string($rsa['e'])) {
            throw new RuntimeException('the key is not RSA');
        }

        return [
            'kty' => 'RSA',
            'use' => $use,
            'kid' => $this->kid,
            'alg' => $algorithm,
            'n' => Base64Url::encode($rsa['n']),
            'e' => Base64Url::encode($rsa['e']),
        ];
    }

    /** A key set holding exactly this key. */
    public function jwks(string $algorithm = 'RS256'): string
    {
        return self::jwksOf($this->jwk($algorithm));
    }

    /** @param array<string, string> ...$jwks */
    public static function jwksOf(array ...$jwks): string
    {
        return json_encode(['keys' => array_values($jwks)], JSON_THROW_ON_ERROR);
    }

    /**
     * A signed token.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header extra or replacement header fields,
     *     so a test can put a kid or an alg in that does not match the key
     */
    public function token(array $claims, array $header = [], string $algorithm = 'RS256'): string
    {
        $header = [...['alg' => $algorithm, 'typ' => 'JWT', 'kid' => $this->kid], ...$header];

        $signingInput = Base64Url::encode(json_encode($header, JSON_THROW_ON_ERROR))
            . '.'
            . Base64Url::encode(json_encode($claims, JSON_THROW_ON_ERROR));

        $digest = self::DIGESTS[$algorithm] ?? OPENSSL_ALGO_SHA256;

        if (!openssl_sign($signingInput, $signature, $this->key, $digest)) {
            throw new RuntimeException('could not sign the token');
        }

        return $signingInput . '.' . Base64Url::encode($signature);
    }

    /**
     * A token with a signature that is merely bytes - what an attacker without
     * the private key can produce.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header
     */
    public function unsignedToken(array $claims, array $header = [], string $signature = 'not-a-signature'): string
    {
        $header = [...['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid], ...$header];

        return Base64Url::encode(json_encode($header, JSON_THROW_ON_ERROR))
            . '.'
            . Base64Url::encode(json_encode($claims, JSON_THROW_ON_ERROR))
            . '.'
            . Base64Url::encode($signature);
    }
}
