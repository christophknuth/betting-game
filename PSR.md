# PSR-Standards

Analyse, Umsetzungsstand und Verwendung der PHP-FIG-Standards in diesem Projekt.

> ⚠️ **Lesehinweis:** Nicht jedes implementierte Interface hat schon einen Nutzer.
> `CacheInterface` wird produktiv von [KeycloakKeys](src/Infrastructure/Auth/KeycloakKeys.php)
> verwendet (JWKS-Cache), `LoggerInterface` nur von
> [AuthMiddleware](src/Infrastructure/Auth/AuthMiddleware.php). **Command- und
> Query-Handler, Controller und Repositories nutzen weder Logger noch Cache.** Die
> Code-Beispiele unten zeigen an diesen Stellen die vorgesehene Verwendung, nicht den
> Ist-Zustand.

## Umsetzungsstand

| PSR | Thema | Status | Dateien |
|-----|-------|--------|---------|
| PSR-3 | Logger Interface | implementiert, ein Nutzer | `Infrastructure/Logging/LoggerFactory.php` |
| PSR-4 | Autoloader | aktiv | `composer.json` |
| PSR-11 | Container Interface | implementiert, überall genutzt | `Infrastructure/DI/PsrContainer.php` |
| PSR-12 | Coding Style | angewandt | `src`, `tests`, `public`, `config` |
| PSR-16 | Simple Cache | implementiert, ein Nutzer | `Infrastructure/Cache/{FileCache,RedisCache}.php` |

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

tests/Unit/Infrastructure/FileCacheTest.php
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
    "BettingGame\\Presentation\\":   "src/Presentation/",
    "BettingGame\\Support\\":        "src/Support/"
  }
}
```

Die Namespace-Wurzel bleibt `BettingGame\` – historisch, trotz der Lotto-Domäne.
Tests liegen unter `BettingGame\Tests\` (`autoload-dev`).

Nach Strukturänderungen: `composer dump-autoload`.

## PSR-12: Coding Style

- `declare(strict_types=1);` in allen 153 Dateien unter `src/` und in `tests/`
- 4 Spaces Einrückung, Opening Braces bei Klassen auf neuer Zeile
- `final` Klassen als Standard
- Visibility Modifier an allen Properties und Methoden

Prüfen und korrigieren:

```bash
composer cs-check     # phpcs --standard=PSR12 src tests public config
composer cs-fix       # phpcbf --standard=PSR12 src tests public config
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

Aktuell schreibt nur `AuthMiddleware` – abgelehnte Tokens und nicht erreichbare
Schlüsselquellen. So wäre es in einem Command-Handler vorgesehen:

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

if ($container->has(BetRowRepositoryInterface::class)) {
    $repo = $container->get(BetRowRepositoryInterface::class);
}
```

Registrierte Services: `PDO`, `LoggerInterface`, `CacheInterface`, `KeySource`,
`TokenVerifier`, `KeycloakService`, `AuthMiddleware`, `Db`, `EventStoreInterface`, die
neun Domain-Repository-Interfaces, `ProjectionManager`, die 9 Command- und 10
Query-Handler, die 9 Controller, `Router`, `ErrorMapper` und `Kernel`.

Der `Kernel` löst Controller **zur Laufzeit über den Container** auf – aus dem Namen in der
Routentabelle. Deshalb ist jeder Controller dort einzeln eingetragen; ein neuer, der
vergessen wird, fällt erst beim Aufruf der Route auf.

In Production kompiliert PHP-DI den Container nach `var/cache`
(`'production' => true` in `config/config.php`, gesetzt über `APP_ENV=production`).

## PSR-16: Simple Cache

Zwei Implementierungen mit identischem Interface:

| | FileCache | RedisCache |
|---|-----------|------------|
| Einsatz | Development | Production |
| Voraussetzung | keine | `ext-redis` |
| Skalierbar | nein | ja, shared über mehrere Server |

Die Auswahl trifft der Container anhand von `cache.driver`; ohne geladene `ext-redis`
fällt er auf `FileCache` zurück.

**Produktiver Nutzer:** [KeycloakKeys](src/Infrastructure/Auth/KeycloakKeys.php) legt das
JWKS des Realms für `keycloak.jwks_ttl` Sekunden (Default 300) ab. Ohne diesen Cache holte
jeder einzelne Request das Key Set neu. Ein zuletzt bekanntes Key Set überlebt damit auch
einen kurzen Ausfall von Keycloak.

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
$key = "bet_row:participant:{$participantId}:period:{$betPeriodId}";

// TTL nach Use Case
$cache->set("draws:tipp_year:{$id}", $draws, 3600);   // ändert sich selten: 1 h
$cache->set("fees:participant:{$id}", $fees, 300);    // Teilnehmerdaten: 5 Min
$cache->set('rate_limit:' . $ip, $count, 30);         // temporär: 30 s

// Nach Schreibzugriffen invalidieren
$this->cache->delete("bet_row:participant:{$id}:period:{$betPeriodId}");
```

> Beim Cachen von Read Models ist die Invalidierung der eigentliche Punkt: Repositories
> schreiben ihre Projektion synchron, ein `load()` direkt danach *muss* die neue Zeile
> sehen. Ein Cache davor, der das nicht nachvollzieht, bricht genau diese Zusage.

## Konfiguration

`config/config.php` liest alle Werte aus Environment-Variablen:

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

Gelesen wird über `getenv()`, **nicht** über `$_ENV`: das ist nur befüllt, wenn die
ini-Einstellung `variables_order` ein `E` enthält, was die offiziellen PHP-Images nicht
setzen. Über `$_ENV` fiel früher jeder Wert still auf seinen Default zurück — die
Anwendung war schlicht nicht konfigurierbar. Der Zugriff auf das fertige Array läuft über
[Config](src/Infrastructure/Config/Config.php), das typisiert liest statt zu casten.

Passende Variablen stehen in [`.env.example`](.env.example).

## Nicht implementierte Standards

| PSR | Thema | Bewertung |
|-----|-------|-----------|
| PSR-6 | Caching Interface | mächtiger, aber komplexer als PSR-16 – aktuell nicht nötig |
| PSR-7 | HTTP Message | eigene `Request`/`JsonResponse` vorhanden und klein; Umstellung nur zusammen mit PSR-15 sinnvoll |
| PSR-14 | Event Dispatcher | Event Sourcing ist vorhanden; interessant, sobald `event_publisher` tatsächlich geleert wird |
| PSR-15 | HTTP Handlers & Middleware | der `Kernel` ruft `AuthMiddleware` heute direkt auf; eine echte Kette lohnt ab der zweiten Middleware, setzt PSR-7 voraus |
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

1. `LoggerInterface` in Command-Handler injizieren — eine abgelehnte Buchung ist heute
   nirgends nachlesbar außer im HTTP-Status des Aufrufers
2. `CacheInterface` in den Query-Handlern nutzen, inklusive Invalidierung beim Schreiben
3. Redis-Service in `docker-compose.yml` ergänzen (die Implementierung existiert bereits,
   der Container nicht)
