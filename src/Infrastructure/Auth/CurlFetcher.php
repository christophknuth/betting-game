<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Auth;

/**
 * The real fetcher.
 *
 * Timeouts are short and explicit. This call sits in the authentication path of
 * every request, so a Keycloak that accepts connections and then stops talking
 * must not be able to hold our workers open until they run out.
 */
final class CurlFetcher implements HttpFetcher
{
    public function __construct(
        private int $connectTimeoutSeconds = 2,
        private int $timeoutSeconds = 5
    ) {
    }

    public function get(string $url): ?string
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return null;
        }

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $this->connectTimeoutSeconds);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($status !== 200 || !is_string($response)) {
            return null;
        }

        return $response;
    }
}
