<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

/**
 * A GET against a URL, returning the body or null.
 *
 * An interface so the key fetching can be tested without a Keycloak: the
 * behaviour worth testing there is the caching and the refresh throttle, not
 * curl.
 */
interface HttpFetcher
{
    public function get(string $url): ?string;
}
