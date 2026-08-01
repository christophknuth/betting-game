# PSR standards

Analysis, state of implementation and use of the PHP-FIG standards in this project.

> ⚠️ **A note before reading:** not every implemented interface already has a user.
> `CacheInterface` is used in production by [KeycloakKeys](src/Infrastructure/Auth/KeycloakKeys.php)
> (the JWKS cache), `LoggerInterface` by
> [AuthMiddleware](src/Infrastructure/Auth/AuthMiddleware.php) and the
> [Kernel](src/Presentation/Http/Kernel.php). **Command and query handlers, controllers and
> repositories use neither logger nor cache.** In those places the code examples below show
> the intended use, not the current state.

## State of implementation

| PSR | Topic | Status | Files |
|-----|-------|--------|-------|
| PSR-3 | Logger Interface | implemented, two users, writes to the container's output | `Infrastructure/Logging/LoggerFactory.php` |
| PSR-4 | Autoloader | active | `composer.json` |
| PSR-11 | Container Interface | implemented, used everywhere | `Infrastructure/DI/PsrContainer.php` |
| PSR-12 | Coding Style | applied | `src`, `tests`, `public`, `config` |
| PSR-16 | Simple Cache | implemented, one user | `Infrastructure/Cache/{FileCache,RedisCache}.php` |

### Directory structure

```
src/Infrastructure/
├── Logging/
│   └── LoggerFactory.php          # PSR-3
├── Cache/
│   ├── FileCache.php              # PSR-16 file (+ CacheInvalidArgumentException)
│   └── RedisCache.php             # PSR-16 Redis
└── DI/
    ├── Container.php              # container definitions
    └── PsrContainer.php           # PSR-11 adapter (+ Container[NotFound]Exception)

var/cache/    # cache files (the compiled DI container, the JWKS cache)

tests/Unit/Infrastructure/FileCacheTest.php
```

> `CacheInvalidArgumentException`, `ContainerNotFoundException` and `ContainerException`
> each live in the file of their main class – the only two exceptions to the
> one-class-per-file rule.

### Dependencies

```json
"psr/log": "^3.0",              // PSR-3 interface
"psr/container": "^2.0",        // PSR-11 interface
"psr/simple-cache": "^3.0",     // PSR-16 interface
"monolog/monolog": "^3.5"       // PSR-3 implementation
```

## PSR-4: autoloader

The namespace maps 1:1 onto the directory structure:

```
BettingGame\Domain\ValueObject\ParticipantId
→ src/Domain/ValueObject/ParticipantId.php
```

```json
"autoload": {
  "psr-4": {
    "BettingGame\\Domain\\":         "src/Domain/",
    "BettingGame\\Application\\":    "src/Application/",
    "BettingGame\\Infrastructure\\": "src/Infrastructure/",
    "BettingGame\\Presentation\\":   "src/Presentation/",
    "BettingGame\\Support\\":        "src/Support/"
  }
}
```

The namespace root stays `BettingGame\` – historically, despite the lotto domain.
Tests live under `BettingGame\Tests\` (`autoload-dev`).

After structural changes: `composer dump-autoload`.

## PSR-12: coding style

- `declare(strict_types=1);` in all 153 files under `src/` and in `tests/`
- 4 spaces of indentation, opening braces of classes on a new line
- `final` classes by default
- visibility modifiers on all properties and methods

Check and fix:

```bash
composer cs-check     # phpcs --standard=PSR12 src tests public config
composer cs-fix       # phpcbf --standard=PSR12 src tests public config
```

## PSR-3: Logger Interface

`LoggerFactory` creates four preconfigured loggers on top of Monolog:

| Logger | Channel | Purpose |
|--------|---------|---------|
| `createApplicationLogger()` | `betting-game` | general application logging |
| `createEventStoreLogger()` | `event-store` | event sourcing operations |
| `createErrorLogger()` | `errors` | critical errors |
| `createCqrsLogger()` | `cqrs` | command/query processing |

**Everything goes to the container's output**, not to a file. They used to write into
`var/log/*.log` with a rotating handler, which in a container is the wrong end: the files
sit in a filesystem nobody looks at, `docker-compose logs` shows nothing, and rotation
duplicates what the runtime already does.

Records from `warning` upwards go to **stderr**, the rest to **stdout**, so a runtime that
separates the two keeps complaints apart from routine chatter. The format follows the
environment: JSON in production, because something is going to parse it, and a readable
line in development, because a person is. The floor is `info` in production and `debug`
otherwise.

> The push order in `streamLogger()` is worth reading before changing: Monolog calls
> handlers in reverse, so stderr is pushed *last* to run *first*, and it stops the record
> bubbling. Pushed the other way round, warnings would go to stdout and never reach stderr.

### Log levels (RFC 5424)

`emergency` · `alert` · `critical` · `error` · `warning` · `notice` · `info` · `debug`

### Use

Two places write today. `AuthMiddleware` records rejected tokens and unreachable key
sources, and the [`Kernel`](src/Presentation/Http/Kernel.php) records every command:

```json
{"message":"Command accepted","context":{"command":"AdminParticipantController::create",
 "commandId":"3b355714-…","actor":"admin","status":202,"resourceId":49},"level_name":"INFO"}

{"message":"Command rejected","context":{"command":"AdminParticipantController::create",
 "commandId":"9a4d6ae5-…","actor":"admin","status":400,
 "reason":"Display name must be at least 2 characters",
 "exception":"…\\InvalidArgumentException"},"level_name":"WARNING"}
```

That is deliberate division of labour with the interface. The `commandId` used to be
printed under every write in the SPA, where it meant nothing to the person reading it; a
rejected business rule was explained on screen in terms of the rule. Both now go here,
where whoever is diagnosing something will look, and the interface says only whether it
worked. `CommandLoggingTest` pins the three outcomes down.

A rejection is a **warning**, not an error: a business rule saying no is that rule doing its
job.

This is how the logger would be intended in a command handler, which still does not use it:

```php
use Psr\Log\LoggerInterface;

final class SubmitTicketHandler
{
    public function __construct(
        private LoggerInterface $logger,
        // ...
    ) {}

    public function handle(SubmitTicketCommand $command): CommandResult
    {
        $this->logger->info('Submitting ticket', [
            'tipp_year_id' => $command->tippYearId,
            'period_start' => $command->periodStart,
        ]);
        // ...
    }
}
```

**Best practices**

- Choose the level deliberately: `debug` for cache misses, `info` for business events,
  `warning` for limits coming closer, `error` for failures
- Pass context in a structured way rather than interpolating it into the text
- Mask sensitive data (`'password' => '***REDACTED***'`)

In tests: `Psr\Log\NullLogger` or `Psr\Log\Test\TestLogger`.

## PSR-11: Container Interface

`PsrContainer` wraps the PHP-DI container and implements
`Psr\Container\ContainerInterface` with `get()` and `has()`.

```php
$container = Container::build($config);

$logger = $container->get(LoggerInterface::class);

if ($container->has(BetRowRepositoryInterface::class)) {
    $repo = $container->get(BetRowRepositoryInterface::class);
}
```

Registered services: `PDO`, `LoggerInterface`, `CacheInterface`, `KeySource`,
`TokenVerifier`, `KeycloakService`, `AuthMiddleware`, `Db`, `EventStoreInterface`, the
nine domain repository interfaces, `ProjectionManager`, the 9 command and 10 query
handlers, the 9 controllers, `Router`, `ErrorMapper` and `Kernel`.

The `Kernel` resolves controllers **at runtime through the container** – from the name in
the routing table. That is why every controller is registered there individually; one that
is forgotten only shows up when its route is called.

In production PHP-DI compiles the container into `var/cache`
(`'production' => true` in `config/config.php`, set through `APP_ENV=production`).

## PSR-16: Simple Cache

Two implementations with an identical interface:

| | FileCache | RedisCache |
|---|-----------|------------|
| Used in | development | production |
| Prerequisite | none | `ext-redis` |
| Scalable | no | yes, shared across several servers |

The container makes the choice based on `cache.driver`; without a loaded `ext-redis` it
falls back to `FileCache`.

**User in production:** [KeycloakKeys](src/Infrastructure/Auth/KeycloakKeys.php) stores the
realm's JWKS for `keycloak.jwks_ttl` seconds (default 300). Without this cache every single
request would fetch the key set anew. A last-known key set therefore also survives a short
Keycloak outage.

```php
$cache->set('key', 'value', 3600);              // TTL in seconds
$cache->set('key', 'value', new DateInterval('PT1H'));
$cache->set('key', 'value', null);              // default TTL from the constructor

$value = $cache->get('key', 'default');
$cache->has('key');
$cache->delete('key');
$cache->clear();

$cache->setMultiple(['k1' => 'v1', 'k2' => 'v2']);
$values = $cache->getMultiple(['k1', 'k2'], 'default');
$cache->deleteMultiple(['k1', 'k2']);
```

**Cache keys** – `A-Za-z0-9_.` are allowed, `{}()/@:` are forbidden.

**Best practices**

```php
// Structure the keys
$key = "bet_row:participant:{$participantId}:period:{$betPeriodId}";

// TTL by use case
$cache->set("draws:tipp_year:{$id}", $draws, 3600);   // rarely changes: 1 h
$cache->set("fees:participant:{$id}", $fees, 300);    // participant data: 5 min
$cache->set('rate_limit:' . $ip, $count, 30);         // temporary: 30 s

// Invalidate after writes
$this->cache->delete("bet_row:participant:{$id}:period:{$betPeriodId}");
```

> When caching read models, invalidation is the actual point: repositories write their
> projection synchronously, and a `load()` right afterwards *must* see the new row. A cache
> in front of that which does not follow along breaks exactly that promise.

## Configuration

`config/config.php` reads every value from environment variables:

```php
'environment' => $env('APP_ENV', 'development'),

'cache' => [
    'driver' => $env('CACHE_DRIVER', 'file'),      // file | redis
    'ttl'    => (int) $env('CACHE_TTL', '3600'),
    'path'   => $env('CACHE_PATH', __DIR__ . '/../var/cache'),
    'redis'  => [
        'host'     => $env('REDIS_HOST', '127.0.0.1'),
        'port'     => (int) $env('REDIS_PORT', '6379'),
        'password' => $env('REDIS_PASSWORD'),
        'database' => (int) $env('REDIS_DATABASE', '0'),
    ],
],
```

Reading goes through `getenv()`, **not** through `$_ENV`: the latter is only populated when
the ini setting `variables_order` contains an `E`, which the official PHP images do not set.
Through `$_ENV` every value used to fall back silently to its default — the application was
simply not configurable. Access to the finished array goes through
[Config](src/Infrastructure/Config/Config.php), which reads typed instead of casting.

The matching variables are listed in [`.env.example`](.env.example).

## Standards that are not implemented

| PSR | Topic | Assessment |
|-----|-------|------------|
| PSR-6 | Caching Interface | more powerful, but more complex than PSR-16 – not needed right now |
| PSR-7 | HTTP Message | our own `Request`/`JsonResponse` exist and are small; switching only makes sense together with PSR-15 |
| PSR-14 | Event Dispatcher | event sourcing is in place; interesting as soon as `event_publisher` is actually drained |
| PSR-15 | HTTP Handlers & Middleware | the `Kernel` calls `AuthMiddleware` directly today; a real chain pays off from the second middleware on, and requires PSR-7 |
| PSR-18 | HTTP Client | only relevant once external APIs are consumed |

**Order, should the need arise:** PSR-7 + PSR-15 together (middleware chain), then PSR-14
for asynchronous events.

## Expected performance impact

> Estimates from the design phase, not measured in this project.

| Building block | Overhead | Benefit |
|----------------|----------|---------|
| Logging | ~0.5–2 ms per request | indispensable for debugging and monitoring |
| Caching | ~1 ms per cache check | query time saved, once read models are cached |
| Container | < 0.1 ms | cleaner architecture, exchangeable implementations |

## Next steps

1. Inject `LoggerInterface` into command handlers — a rejected booking cannot be looked up
   anywhere today except in the caller's HTTP status
2. Use `CacheInterface` in the query handlers, including invalidation on writes
3. Add a Redis service to `docker-compose.yml` (the implementation already exists, the
   container does not)
