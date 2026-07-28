<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

use InvalidArgumentException;

/**
 * Checks that a JWS really was issued by our Keycloak realm.
 *
 * Until this class existed the application read the claims out of the token and
 * believed them. Anyone could mint a token naming any participant_id and the
 * admin role, so every rule expressed as "only this participant" or "only an
 * admin" was decoration. The signature is what turns those claims into
 * statements by Keycloak instead of statements by the caller.
 *
 * Only the RSASSA-PKCS1-v1_5 family is accepted - Keycloak's default, and what
 * openssl_verify() handles natively. ES* and PS* are refused outright rather
 * than waved through; supporting them is a contained change here, silently
 * accepting them would not be.
 */
final class TokenVerifier
{
    /** @var array<string, int> */
    private const DIGESTS = [
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS384' => OPENSSL_ALGO_SHA384,
        'RS512' => OPENSSL_ALGO_SHA512,
    ];

    /** @var non-empty-list<string> */
    private array $algorithms;

    /**
     * @param string      $issuer     the exact iss claim expected. Keycloak
     *     issues its *frontend* URL, which need not be the URL we reach it on.
     * @param string|null $audience   checked against aud when configured
     * @param int         $leewaySeconds tolerance for clock drift between us
     *     and Keycloak
     * @param list<string> $algorithms
     *
     * @throws InvalidArgumentException on an algorithm we will not verify with.
     *     Refused at construction, not at request time: an application
     *     configured to accept HS256 would accept a token signed with the
     *     public key everyone can read, and that must fail at boot where
     *     somebody sees it.
     */
    public function __construct(
        private KeySource $keys,
        private string $issuer,
        private ?string $audience = null,
        private int $leewaySeconds = 30,
        array $algorithms = ['RS256']
    ) {
        if ($algorithms === []) {
            throw new InvalidArgumentException('at least one signing algorithm must be accepted');
        }

        $unsupported = array_diff($algorithms, array_keys(self::DIGESTS));

        if ($unsupported !== []) {
            throw new InvalidArgumentException(
                'unsupported signing algorithm: ' . implode(', ', $unsupported)
            );
        }

        $this->algorithms = array_values($algorithms);
    }

    /**
     * @return array<string, mixed> the verified claims
     *
     * @throws InvalidTokenException  the token is not one we accept
     * @throws KeyUnavailableException we cannot tell either way right now
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new InvalidTokenException('not a three-part JWS');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->segment($encodedHeader, 'header');

        $algorithm = $header['alg'] ?? null;

        if (!is_string($algorithm)) {
            throw new InvalidTokenException('the header names no algorithm');
        }

        // Before anything is decoded, because this is the answer to two of the
        // classic forgeries and the reason should say so. The allowlist can
        // only hold asymmetric algorithms - the constructor sees to that - so
        // "none" and the HS* family are unreachable here: neither an unsigned
        // token claiming it needs no signature, nor one signed with HMAC using
        // the public key anyone can fetch from the JWKS endpoint.
        if (!in_array($algorithm, $this->algorithms, true)) {
            throw new InvalidTokenException("$algorithm is not an accepted algorithm");
        }

        $signature = Base64Url::decode($encodedSignature);

        if ($signature === null || $signature === '') {
            throw new InvalidTokenException('the signature is not base64url');
        }

        $kid = $header['kid'] ?? null;
        $key = $this->keyFor(is_string($kid) ? $kid : null, $algorithm);

        $verified = openssl_verify(
            "$encodedHeader.$encodedPayload",
            $signature,
            $key->pem,
            self::DIGESTS[$algorithm]
        );

        if ($verified !== 1) {
            throw new InvalidTokenException("the signature does not match key $key->kid");
        }

        // Only now is the payload worth reading: before this point it is
        // whatever the caller typed.
        $claims = $this->segment($encodedPayload, 'payload');
        $this->assertClaims($claims);

        return $claims;
    }

    /** @throws InvalidTokenException|KeyUnavailableException */
    private function keyFor(?string $kid, string $algorithm): VerificationKey
    {
        $key = $this->keys->keys()->keyFor($kid, $algorithm);

        if ($key !== null) {
            return $key;
        }

        // A kid we do not know is the one honest sign that our copy of the key
        // set is behind - Keycloak signs with the new key the moment it
        // rotates. Refetch and look once more; the source throttles how often
        // that can actually reach the network.
        $refreshed = $this->keys->refresh();
        $key = $refreshed->keyFor($kid, $algorithm);

        if ($key === null) {
            throw new InvalidTokenException(sprintf(
                'no key for kid %s and algorithm %s (the realm publishes: %s)',
                $kid ?? '(none given)',
                $algorithm,
                implode(', ', $refreshed->kids()) ?: 'nothing'
            ));
        }

        return $key;
    }

    /** @param array<string, mixed> $claims */
    private function assertClaims(array $claims): void
    {
        $now = time();

        $expiry = $this->timestamp($claims, 'exp');

        if ($expiry === null) {
            throw new InvalidTokenException('the token carries no expiry');
        }

        if ($now >= $expiry + $this->leewaySeconds) {
            throw new InvalidTokenException('the token expired at ' . gmdate('c', $expiry));
        }

        $notBefore = $this->timestamp($claims, 'nbf');

        if ($notBefore !== null && $now + $this->leewaySeconds < $notBefore) {
            throw new InvalidTokenException('the token is not valid yet');
        }

        $issuedAt = $this->timestamp($claims, 'iat');

        if ($issuedAt !== null && $now + $this->leewaySeconds < $issuedAt) {
            throw new InvalidTokenException('the token was issued in the future');
        }

        $issuer = $claims['iss'] ?? null;

        // A realm other than ours is a valid signature by the wrong authority,
        // which is why this is checked even though the signature already
        // passed - a second realm on the same Keycloak has its own keys but
        // would otherwise reach the same endpoints.
        if (!is_string($issuer) || !hash_equals($this->issuer, $issuer)) {
            throw new InvalidTokenException('issued by ' . (is_string($issuer) ? $issuer : 'nobody'));
        }

        if ($this->audience !== null && !in_array($this->audience, $this->audiences($claims), true)) {
            throw new InvalidTokenException("the token is not addressed to $this->audience");
        }
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return list<string> aud is either one string or a list of them
     */
    private function audiences(array $claims): array
    {
        $audience = $claims['aud'] ?? null;

        if (is_string($audience)) {
            return [$audience];
        }

        if (!is_array($audience)) {
            return [];
        }

        $values = [];
        foreach ($audience as $entry) {
            if (is_string($entry)) {
                $values[] = $entry;
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $claims */
    private function timestamp(array $claims, string $name): ?int
    {
        $value = $claims[$name] ?? null;

        if (is_int($value)) {
            return $value;
        }

        // JSON has one number type, so a whole second can arrive as a float.
        if (is_float($value) && is_finite($value)) {
            return (int) $value;
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function segment(string $encoded, string $what): array
    {
        $json = Base64Url::decode($encoded);

        if ($json === null) {
            throw new InvalidTokenException("the $what is not base64url");
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new InvalidTokenException("the $what is not a JSON object");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
