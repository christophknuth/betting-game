<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

use Psr\SimpleCache\CacheInterface;

/**
 * The realm's published signing keys, fetched from Keycloak's JWKS endpoint and
 * cached.
 *
 * Caching is not an optimisation here, it is what makes the endpoint usable at
 * all: without it every authenticated request would wait on an outbound HTTP
 * call to Keycloak before it could even look at the token.
 */
final class KeycloakKeys implements KeySource
{
    private ?JwkSet $memo = null;
    private string $freshKey;
    private string $fallbackKey;
    private string $throttleKey;

    /**
     * @param int $ttlSeconds        how long a fetched set is used before we
     *     look again
     * @param int $fallbackTtlSeconds how long a set stays usable when Keycloak
     *     cannot be reached at all
     * @param int $refreshIntervalSeconds the shortest gap between two forced
     *     refetches
     */
    public function __construct(
        private string $jwksUrl,
        private HttpFetcher $fetcher,
        private CacheInterface $cache,
        private int $ttlSeconds = 300,
        private int $fallbackTtlSeconds = 86400,
        private int $refreshIntervalSeconds = 60
    ) {
        // PSR-16 reserves {}()/\@: in key names, so the URL is hashed rather
        // than embedded. It is only there to keep two realms apart.
        $scope = md5($jwksUrl);

        $this->freshKey = "jwks_fresh_$scope";
        $this->fallbackKey = "jwks_last_known_$scope";
        $this->throttleKey = "jwks_refreshed_$scope";
    }

    public function keys(): JwkSet
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        return $this->memo = $this->cached($this->freshKey) ?? $this->fetch();
    }

    /**
     * Goes back to Keycloak, at most once per refresh interval.
     *
     * The throttle matters because of what triggers a refresh: a kid we do not
     * recognise, and the kid is a field of a token the caller supplies. Without
     * it, a stream of invented kids becomes a stream of outbound requests to
     * Keycloak, with our own authentication path as the amplifier.
     */
    public function refresh(): JwkSet
    {
        if ($this->cache->get($this->throttleKey) !== null) {
            return $this->keys();
        }

        $this->cache->set($this->throttleKey, 1, $this->refreshIntervalSeconds);
        $this->memo = null;

        return $this->memo = $this->fetch();
    }

    /** @throws KeyUnavailableException */
    private function fetch(): JwkSet
    {
        $body = $this->fetcher->get($this->jwksUrl);

        if ($body !== null) {
            $set = JwkSet::fromJson($body);

            if (!$set->isEmpty()) {
                $this->cache->set($this->freshKey, $body, $this->ttlSeconds);
                $this->cache->set($this->fallbackKey, $body, $this->fallbackTtlSeconds);

                return $set;
            }
        }

        // Keycloak is unreachable, or answering with something that holds no
        // key we can use. Signing keys rotate on the order of months while
        // tokens expire within the hour, so yesterday's key set still verifies
        // today's tokens - which beats refusing every request for the length of
        // the outage. Only when we have never seen a key set do we give up.
        $fallback = $this->cached($this->fallbackKey);

        if ($fallback !== null) {
            return $fallback;
        }

        throw new KeyUnavailableException(
            "no signing keys: $this->jwksUrl could not be read and nothing is cached"
        );
    }

    private function cached(string $key): ?JwkSet
    {
        $value = $this->cache->get($key);

        if (!is_string($value)) {
            return null;
        }

        $set = JwkSet::fromJson($value);

        return $set->isEmpty() ? null : $set;
    }
}
