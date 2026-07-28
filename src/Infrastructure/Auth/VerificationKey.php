<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

/**
 * One public key from the key set, ready for openssl_verify().
 */
final class VerificationKey
{
    /**
     * @param string      $kid       the key id it was published under
     * @param string      $pem       PEM-encoded SubjectPublicKeyInfo
     * @param string|null $algorithm the algorithm the key set pins this key to,
     *     if it declares one. A key published for RS256 must not be used to
     *     check an RS512 signature - the publisher said what it is for.
     */
    public function __construct(
        public readonly string $kid,
        public readonly string $pem,
        public readonly ?string $algorithm
    ) {
    }
}
