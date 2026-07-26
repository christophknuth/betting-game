# PSR-Standards

Analyse, Umsetzungsstand und Verwendung der PHP-FIG-Standards in diesem Projekt.

> ⚠️ **Lesehinweis:** `LoggerInterface` und `CacheInterface` sind implementiert und im
> DI-Container registriert, werden aber **von keinem Handler, Controller oder Repository
> injiziert**. Die Code-Beispiele unten zeigen die vorgesehene Verwendung, nicht den
> Ist-Zustand. Einziger produktiver Nutzer eines PSR-Interfaces ist derzeit
> `AuthMiddleware` (Logger) – und die Middleware wird selbst noch nicht aufgerufen
> (siehe [ARCHITECTURE.md](ARCHITECTURE.md)).

## Umsetzungsstand

| PSR | Thema | Status | Dateien |
|-----|-------|--------|---------|
| PSR-3 | Logger Interface | implementiert | `Infrastructure/Logging/LoggerFactory.php` |
| PSR-4 | Autoloader | aktiv | `composer.json` |
| PSR-11 | Container Interface | implementiert | `Infrastructure/DI/PsrContainer.php` |
| PSR-12 | Coding Style | angewandt | gesamtes `src/` |
| PSR-16 | Simple Cache | implementiert | `Infrastructure/Cache/{FileCache,RedisCache}.php` |

### Verzeichnisstruktur

```
src/Infrastructure/
├── Logging/
│   └── LoggerFactory.php          # PSR-3
├── Cache/
│   ├── FileCache.php              # PSR-16 File (+ CacheInvalidArgumentException)
│   └── RedisCache.php             # PSR-16 Redis
└── DI/
    ├── Container.php              # Container-Definitionen
    └── PsrContainer.php           # PSR-11 Adapter (+ Container[NotFound]Exception)

var/cache/    # Cache-Dateien
var/log/      # Log-Dateien

tests/Unit/Infrastructure/FileCacheTest.php   # 12 Tests
```

> `CacheInvalidArgumentException`, `ContainerNotFoundException` und `ContainerException`
> liegen jeweils in der Datei ihrer Hauptklasse – die einzigen zwei Ausnahmen von der
> One-Class-Per-File-Regel.

### Dependencies

```json
"psr/log": "^3.0",              // PSR-3 Interface
"psr/container": "^2.0",        // PSR-11 Interface
"psr/simple-cache": "^3.0",     // PSR-16 Interface
"monolog/monolog": "^3.5"       // PSR-3 Implementierung
```

## PSR-4: Autoloader

Namespace entspricht 1:1 der Verzeichnisstruktur:

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
    "BettingGame\\Presentation\\":   "src/Presentation/"
  }
}
```

Nach Strukturänderungen: `composer dump-autoload`.

## PSR-12: Coding Style

- `declare(strict_types=1);` in allen 111 Dateien unter `src/`
- 4 Spaces Einrückung, Opening Braces bei Klassen auf neuer Zeile
- `final` Klassen als Standard
- Visibility Modifier an allen Properties und Methoden

Prüfen und korrigieren:

```bash
composer cs-check     # phpcs --standard=PSR12 src
composer cs-fix       # phpcbf --standard=PSR12 src
```

## PSR-3: Logger Interface

`LoggerFactory` erzeugt vier vorkonfigurierte Logger auf Monolog-Basis:

| Logger | Log-Datei | Zweck |
|--------|-----------|-------|
| `createApplicationLogger()` | `var/log/app.log` | allgemeines Application Logging |
| `createEventStoreLogger()` | `var/log/event-store.log` | Event-Sourcing-Operationen |
| `createErrorLogger()` | `var/log/error.log` | kritische Fehler |
| `createCqrsLogger()` | `var/log/cqrs.log` | Command-/Query-Verarbeitung |

Development nutzt Stream Handler mit DEBUG-Level, Production einen Rotating File Handler
mit höherem Schwellwert. Gesteuert über `APP_ENV` bzw. `config('environment')`.

### Log Levels (RFC 5424)

`emergency` · `alert` · `critical` · `error` · `warning` · `notice` · `info` · `debug`

### Verwendung

```php
use Psr\Log\LoggerInterface;

final class SubmitPredictionHandler
{
    public function __construct(
        private LoggerInterface $logger,
        // ...
    ) {}

    public function handle(SubmitPredictionCommand $command): CommandResult
    {
        $this->logger->info('Submitting prediction', [
            'participant_id' => $command->participantId,
            'event_id'       => $command->eventId,
        ]);
        // ...
    }
}
```

**Best Practices**

- Level bewusst wählen: `debug` für Cache-Misses, `info` für Geschäftsereignisse,
  `warning` für nahende Limits, `error` für Fehlschläge
- Kontext strukturiert mitgeben statt in den Text zu interpolieren
- Sensible Daten maskieren (`'password' => '***REDACTED***'`)

In Tests: `Psr\Log\NullLogger` oder `Psr\Log\Test\TestLogger`.

## PSR-11: Container Interface

`PsrContainer` kapselt den PHP-DI-Container und implementiert
`Psr\Container\ContainerInterface` mit `get()` und `has()`.

```php
$container = Container::build($config);

$logger = $container->get(LoggerInterface::class);

if ($container->has(PredictionRepositoryInterface::class)) {
    $repo = $container->get(PredictionRepositoryInterface::class);
}
```

Registrierte Services: `PDO`, `LoggerInterface`, `CacheInterface`, `KeycloakService`,
`AuthMiddleware`, `EventStoreInterface`, die Domain-Repository-Interfaces, Read-Model-
Repositories, Command-/Query-Handler, Controller und `Router`.

> Für die neueren Admin- und Leaderboard-Interfaces fehlen die Bindings noch, ebenso
> die Klasse `Persistence\PredictionRepository` – siehe [ARCHITECTURE.md](ARCHITECTURE.md).

## PSR-16: Simple Cache

Zwei Implementierungen mit identischem Interface:

| | FileCache | RedisCache |
|---|-----------|------------|
| Einsatz | Development | Production |
| Voraussetzung | keine | `ext-redis` |
| Skalierbar | nein | ja, shared über mehrere Server |

Die Auswahl trifft der Container anhand von `cache.driver`; ohne geladene `ext-redis`
fällt er auf `FileCache` zurück.

```php
$cache->set('key', 'value', 3600);              // TTL in Sekunden
$cache->set('key', 'value', new DateInterval('PT1H'));
$cache->set('key', 'value', null);              // Default-TTL aus dem Konstruktor

$value = $cache->get('key', 'default');
$cache->has('key');
$cache->delete('key');
$cache->clear();

$cache->setMultiple(['k1' => 'v1', 'k2' => 'v2']);
$values = $cache->getMultiple(['k1', 'k2'], 'default');
$cache->deleteMultiple(['k1', 'k2']);
```

**Cache Keys** – erlaubt sind `A-Za-z0-9_.`, verboten `{}()/@:`.

**Best Practices**

```php
// Keys strukturieren
$key = "predictions:participant:{$participantId}:game:{$gameId}";

// TTL nach Use Case
$cache->set('game_types', $types, 86400);            // statisch: 24 h
$cache->set("predictions:{$userId}", $preds, 300);   // User-Daten: 5 Min
$cache->set('rate_limit:' . $ip, $count, 30);        // temporär: 30 s

// Nach Schreibzugriffen invalidieren
$this->cache->delete("predictions:participant:{$id}");
```

## Konfiguration

`config/config.php` liest alle Werte aus Environment-Variablen:

```php
'environment' => $_ENV['APP_ENV'] ?? 'development',

'cache' => [
    'driver' => $_ENV['CACHE_DRIVER'] ?? 'file',   // file | redis
    'ttl'    => $_ENV['CACHE_TTL'] ?? 3600,
    'path'   => $_ENV['CACHE_PATH'] ?? __DIR__ . '/../var/cache',
    'redis'  => [
        'host'     => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
        'port'     => $_ENV['REDIS_PORT'] ?? 6379,
        'password' => $_ENV['REDIS_PASSWORD'] ?? null,
        'database' => $_ENV['REDIS_DATABASE'] ?? 0,
    ],
],
```

Passende Variablen stehen in [`.env.example`](.env.example).

## Nicht implementierte Standards

| PSR | Thema | Bewertung |
|-----|-------|-----------|
| PSR-6 | Caching Interface | mächtiger, aber komplexer als PSR-16 – aktuell nicht nötig |
| PSR-7 | HTTP Message | eigene Request/Response-Klassen vorhanden; Umstellung wäre umfangreich, sinnvoll erst zusammen mit PSR-15 |
| PSR-14 | Event Dispatcher | Event Sourcing ist vorhanden; interessant für asynchrone Verarbeitung |
| PSR-15 | HTTP Handlers & Middleware | sinnvoll für die Auth-Middleware, setzt PSR-7 voraus |
| PSR-18 | HTTP Client | erst relevant, wenn externe APIs konsumiert werden |

**Reihenfolge bei Bedarf:** PSR-7 + PSR-15 gemeinsam (Middleware-Kette), danach PSR-14
für asynchrone Events.

## Erwarteter Performance-Impact

> Schätzwerte aus der Entwurfsphase, in diesem Projekt nicht gemessen.

| Baustein | Overhead | Nutzen |
|----------|----------|--------|
| Logging | ~0,5–2 ms pro Request | unverzichtbar für Debugging und Monitoring |
| Caching | ~1 ms pro Cache-Check | gesparte Query-Zeit, sobald Read Models gecacht werden |
| Container | < 0,1 ms | sauberere Architektur, austauschbare Implementierungen |

## Nächste Schritte

1. `LoggerInterface` in Command-/Query-Handler injizieren
2. `CacheInterface` in den Read-Model-Repositories nutzen, inkl. Invalidierung
3. Redis-Service in `docker-compose.yml` ergänzen (Implementierung existiert bereits)
