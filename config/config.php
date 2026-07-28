<?php

declare(strict_types=1);

// Reads an environment variable.
//
// Goes through getenv() rather than $_ENV: $_ENV is only populated when the
// `variables_order` ini setting contains an "E", which the official PHP images
// do not set. Reading $_ENV directly meant every value here silently fell back
// to its default, so the application could not be configured at all.
//
// Undefined keys must not raise a warning either - a warning is output, and
// output before the response means the headers are already sent, which
// silently turns every status code into 200.
$env = static function (string $key, ?string $default = null): ?string {
    $value = getenv($key);

    if ($value === false) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
    }

    if (!is_string($value) || $value === '') {
        return $default;
    }

    return $value;
};

$flag = static function (?string $value, bool $default): bool {
    if ($value === null) {
        return $default;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
};

$environment = $env('APP_ENV', 'development') ?? 'development';

return [
    'debug' => $flag($env('APP_DEBUG'), $environment !== 'production'),
    'production' => $environment === 'production',
    'environment' => $environment,

    'db' => [
        'host' => $env('DB_HOST', 'localhost'),
        'port' => (int) ($env('DB_PORT', '3306') ?? '3306'),
        'database' => $env('DB_DATABASE', 'betting_game'),
        'username' => $env('DB_USERNAME', 'root'),
        'password' => $env('DB_PASSWORD', ''),
    ],

    'cache' => [
        'driver' => $env('CACHE_DRIVER', 'file'), // file, redis
        'ttl' => (int) ($env('CACHE_TTL', '3600') ?? '3600'),
        'path' => $env('CACHE_PATH', __DIR__ . '/../var/cache'),
        'redis' => [
            'host' => $env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) ($env('REDIS_PORT', '6379') ?? '6379'),
            'password' => $env('REDIS_PASSWORD'),
            'database' => (int) ($env('REDIS_DATABASE', '0') ?? '0'),
        ],
    ],

    'keycloak' => [
        'url' => $env('KEYCLOAK_URL', 'http://keycloak:8080'),
        'realm' => $env('KEYCLOAK_REALM', 'betting-game'),
        'client_id' => $env('KEYCLOAK_CLIENT_ID', 'betting-game-api'),
        'frontend_client_id' => $env('KEYCLOAK_FRONTEND_CLIENT_ID', 'betting-game-frontend'),
    ],

    'jwt' => [
        'secret' => $env('JWT_SECRET', 'your-secret-key-change-in-production'),
        'algorithm' => 'HS256',
        'expiration' => 3600, // 1 hour
    ],

    'oidc' => [
        'issuer' => $env('OIDC_ISSUER', 'https://auth.bettinggame.com'),
        'client_id' => $env('OIDC_CLIENT_ID', ''),
        'client_secret' => $env('OIDC_CLIENT_SECRET', ''),
    ],
];
