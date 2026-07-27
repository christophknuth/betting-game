<?php

declare(strict_types=1);

return [
    'debug' => $_ENV['APP_DEBUG'] ?? true,
    'production' => $_ENV['APP_ENV'] === 'production',
    'environment' => $_ENV['APP_ENV'] ?? 'development',

    'db' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => $_ENV['DB_PORT'] ?? 3306,
        'database' => $_ENV['DB_DATABASE'] ?? 'betting_game',
        'username' => $_ENV['DB_USERNAME'] ?? 'root',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
    ],

    'cache' => [
        'driver' => $_ENV['CACHE_DRIVER'] ?? 'file', // file, redis
        'ttl' => $_ENV['CACHE_TTL'] ?? 3600,
        'path' => $_ENV['CACHE_PATH'] ?? __DIR__ . '/../var/cache',
        'redis' => [
            'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port' => $_ENV['REDIS_PORT'] ?? 6379,
            'password' => $_ENV['REDIS_PASSWORD'] ?? null,
            'database' => $_ENV['REDIS_DATABASE'] ?? 0,
        ],
    ],

    'keycloak' => [
        'url' => $_ENV['KEYCLOAK_URL'] ?? 'http://keycloak:8080',
        'realm' => $_ENV['KEYCLOAK_REALM'] ?? 'betting-game',
        'client_id' => $_ENV['KEYCLOAK_CLIENT_ID'] ?? 'betting-game-api',
        'frontend_client_id' => $_ENV['KEYCLOAK_FRONTEND_CLIENT_ID'] ?? 'betting-game-frontend',
    ],

    'jwt' => [
        'secret' => $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production',
        'algorithm' => 'HS256',
        'expiration' => 3600, // 1 hour
    ],

    'oidc' => [
        'issuer' => $_ENV['OIDC_ISSUER'] ?? 'https://auth.bettinggame.com',
        'client_id' => $_ENV['OIDC_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['OIDC_CLIENT_SECRET'] ?? '',
    ],
];
