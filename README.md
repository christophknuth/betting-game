# Betting Game API

High-performance betting game management API with Event Sourcing, CQRS, and Onion Architecture.

## 📚 Documentation

| Document | Contents |
|----------|----------|
| [QUICKSTART.md](QUICKSTART.md) | Getting started, first API calls, test data |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Layers, patterns, file structure, open issues |
| [DOCKER.md](DOCKER.md) | Docker stack, configuration, troubleshooting |
| [KEYCLOAK.md](KEYCLOAK.md) | Authentication, demo users, tokens |
| [FRONTEND.md](FRONTEND.md) | Vue.js SPA – views, routes, API client |
| [PSR.md](PSR.md) | PSR standards: status and usage |
| [CHANGELOG.md](CHANGELOG.md) | History of the larger rebuilds |

Machine-readable specs: `betting_game_api.yaml` (OpenAPI 3.0),
`betting_game_er_extended.mermaid` (ER diagram), `database/schema.sql`.

## 🏗️ Architecture

### Onion Architecture Layers

```
┌─────────────────────────────────────┐
│      Presentation Layer             │  ← HTTP Controllers, Routing
├─────────────────────────────────────┤
│      Application Layer              │  ← Commands, Queries, Handlers
├─────────────────────────────────────┤
│      Domain Layer (Core)            │  ← Entities, Value Objects, Events
├─────────────────────────────────────┤
│      Infrastructure Layer           │  ← EventStore, Repositories, DB
└─────────────────────────────────────┘
```

### Key Design Patterns

- **CQRS (Command Query Responsibility Segregation)**: Separate read and write models
- **Event Sourcing**: All state changes captured as immutable events
- **Domain-Driven Design**: Rich domain models with business logic
- **Dependency Injection**: Loose coupling via interfaces
- **Repository Pattern**: Abstraction over data access

### Project Structure

```
betting-game/
├── src/
│   ├── Domain/              # Core business logic (no dependencies)
│   │   ├── Model/           # Entities with business rules
│   │   ├── ValueObject/     # Immutable value objects with validation
│   │   ├── Event/           # Domain events for Event Sourcing
│   │   ├── Repository/      # Repository interfaces
│   │   └── Exception/       # Domain exceptions
│   ├── Application/         # Use cases and orchestration
│   │   ├── Command/         # Write operations (CQRS)
│   │   └── Query/           # Read operations (CQRS)
│   ├── Infrastructure/      # External concerns
│   │   ├── Auth/            # Keycloak service + auth middleware
│   │   ├── Cache/           # PSR-16 cache (File, Redis)
│   │   ├── DI/              # Dependency Injection container
│   │   ├── EventStore/      # Event Store implementation
│   │   ├── Logging/         # PSR-3 logger factory (Monolog)
│   │   └── Persistence/     # Repository implementations
│   └── Presentation/        # HTTP layer
│       ├── Controller/      # API controllers
│       ├── Http/            # HTTP helpers
│       └── Router/          # Fast routing
├── tests/
│   └── Unit/                # Unit tests (12 files, 109 test methods)
├── config/                  # Configuration files
├── database/                # SQL schema
├── docker/                  # Dockerfile, Caddyfile, PHP configs
├── keycloak/                # Realm export (users, clients, roles)
├── frontend/                # Vue.js 3 SPA
└── public/                  # Web root
    └── index.php            # Application entry point
```

## 🚀 Features

- ✅ Event Sourcing with full audit trail
- ✅ CQRS for optimized reads and writes
- ✅ Optimistic locking for concurrency control
- ✅ Domain validation with Value Objects
- ✅ Fast routing with FastRoute
- ✅ Minimal dependencies (7 production packages)
- ✅ 109 unit tests across 12 test classes
- ✅ OpenAPI 3.0 compliant
- ✅ **Keycloak Authentication** (OAuth2/OIDC) — frontend end-to-end, backend see note below
- ⚠️ **JWT Token Validation** (Backend) — `KeycloakService`/`AuthMiddleware` implemented, **not yet wired into `public/index.php`**
- ✅ **Role-Based Access Control** (RBAC) — enforced in the frontend router; backend admin check is still a stub
- ✅ **One class per file** (111 files, PSR-4 compliant)
- ✅ **PSR-3: Logger Interface** (Monolog)
- ✅ **PSR-11: Container Interface** (DI Container)
- ✅ **PSR-16: Simple Cache** (File/Redis)
- ✅ **Vue.js 3 Frontend** (Keycloak-Integrated)

## 📦 Dependencies

### Production
- `php: ^8.3` - Latest PHP with performance improvements
- `nikic/fast-route: ^1.3` - Fast HTTP routing
- `php-di/php-di: ^7.0` - Dependency Injection
- `ramsey/uuid: ^4.7` - UUID generation
- `psr/log: ^3.0` - PSR-3 logger interface
- `psr/container: ^2.0` - PSR-11 container interface
- `psr/simple-cache: ^3.0` - PSR-16 cache interface
- `monolog/monolog: ^3.5` - PSR-3 implementation

### Development
- `phpunit/phpunit: ^11.0` - Unit testing
- `phpstan/phpstan: ^1.10` - Static analysis
- `squizlabs/php_codesniffer: ^3.8` - Code style

## 🔧 Installation

### Requirements
- PHP 8.3 or higher
- MariaDB 11.3+ or MySQL 8.0+
- Composer 2.x
- Docker & Docker Compose (for containerized setup)

### Setup with Docker (Recommended)

The project uses a modern Docker stack:
- **PHP-FPM 8.3** (Alpine-based for minimal footprint)
- **Caddy 2.7** (Modern web server with automatic HTTPS)
- **MariaDB 11.3** (Latest stable version)
- **PHPMyAdmin** (Database management)
- **Frontend** (Vue.js 3 build served by Nginx)
- **Keycloak 23.0** + **PostgreSQL 16** (Identity provider and its database)

1. **Start containers**
```bash
make start
# or
docker-compose up -d
```

2. **Install dependencies**
```bash
make composer-install
# or
docker-compose exec php composer install
```

3. **Access the application**
- Frontend: http://localhost:3000
- API: http://localhost:8080
- PHPMyAdmin: http://localhost:8081 (root/secret)
- Keycloak: http://localhost:8090 — Admin Console http://localhost:8090/admin (admin/admin)

### Manual Setup (Without Docker)

1. **Install dependencies**
```bash
composer install
```

2. **Configure database**
```bash
# Copy and edit configuration
cp .env.example .env

# Edit database credentials in .env
# config/config.php reads all values from environment variables
```

3. **Create database and tables**
```bash
mysql -u root -p < database/schema.sql
```

4. **Configure web server**

**Using Docker (Caddy)**
Caddy is pre-configured in `docker/Caddyfile` - no additional setup needed!

**Using Apache (Manual)**
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

**Nginx**
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

5. **Set permissions**
```bash
chmod -R 755 public/
chmod -R 775 var/
```

## 🧪 Testing

### Run all tests
```bash
composer test
```

### Run with coverage
```bash
composer test-coverage
```

### Static analysis
```bash
composer phpstan
```

### Code style check
```bash
composer cs-check
```

## 📊 Database Schema

### Core Tables (Projections)
- `user`, `participant` - User management
- `betting_game`, `game_type`, `event` - Game and event data
- `game_participation` - Participant ↔ game assignment
- `prediction`, `result` - Participant predictions and actual results
- `point_configuration`, `prize_distribution` - Scoring rules
- `participant_score` - Score calculations
- `fee` - Payment tracking

### Event Sourcing Tables
- `event_store` - Immutable event log (source of truth)
- `event_stream` - Stream metadata with version tracking
- `snapshot` - Aggregate snapshots for performance
- `projection_state` - Projection rebuild tracking
- `event_publisher` - Outbox for publishing events downstream

## 🔐 Authentication

The API expects an OIDC/JWT bearer token:

```http
Authorization: Bearer <jwt-token>
```

**Token should contain:**
- `participant_id` - For participant endpoints
- `realm_access.roles` containing `admin` - For admin endpoints

> ⚠️ **Current state:** `public/index.php` still contains a *simulation* of this check — any
> `Bearer` token is accepted, `participant_id` is taken from the URL and admin access is granted
> when the token string contains the substring `admin`. The real implementation
> (`Infrastructure\Auth\KeycloakService` + `AuthMiddleware`, registered in the DI container)
> is **not** invoked yet. Do not deploy this as-is.

## 📡 API Endpoints

### Participant Endpoints

**Predictions**
- `GET /participants/{id}/predictions` - Get own predictions
- `GET /participants/{id}/predictions/{predictionId}` - Get a single prediction
- `POST /participants/{id}/events/{eventId}/predictions` - Submit prediction
- `PUT /participants/{id}/predictions/{predictionId}` - Update prediction

**Scores**
- `GET /participants/{id}/scores` - Get scores and prizes

**Participation**
- `GET /participants/{id}/participations` - Get game participations
- `POST /participants/{id}/games/{gameId}/participation` - Join game
- `DELETE /participants/{id}/games/{gameId}/participation` - Leave game

### Admin Endpoints

**Predictions**
- `GET /admin/predictions` - View all predictions

**Games**
- `GET /admin/games` - List all games
- `POST /admin/games` - Create new game
- `POST /admin/games/{id}/end` - End game

**Results**
- `POST /admin/events/{id}/results` - Record event result

### Public Endpoints

- `GET /health` - Health check (no authentication)

## 🎯 Example Usage

### Submit a Prediction
```bash
curl -X POST http://localhost:8080/participants/1/events/100/predictions \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "predictionData": {
      "homeScore": 2,
      "awayScore": 1
    }
  }'
```

**Response:**
```json
{
  "commandId": "cmd-123",
  "status": "accepted",
  "resourceId": "pred-uuid",
  "timestamp": "2024-01-01T10:00:00+00:00"
}
```

### Get Predictions
```bash
curl -X GET http://localhost:8080/participants/1/predictions \
  -H "Authorization: Bearer <token>"
```

**Response:**
```json
{
  "predictions": [
    {
      "predictionId": "pred-uuid",
      "participantId": 1,
      "eventId": 100,
      "eventName": "Team A vs Team B",
      "predictionData": {"homeScore": 2, "awayScore": 1},
      "submittedAt": "2024-01-01T10:00:00",
      "deadline": "2024-01-01T18:00:00",
      "status": "submitted",
      "isEditable": true
    }
  ],
  "totalCount": 1
}
```

## ⚡ Performance Optimizations

1. **FastRoute** - Compiled route matching (no regex overhead)
2. **Optimistic Locking** - Version-based concurrency control
3. **Event Snapshots** - Avoid replaying large event streams
4. **Read Models** - Pre-computed projections for fast queries
5. **Minimal Dependencies** - Reduced autoload overhead
6. **Opcode Caching** - Enabled in production

### Production Deployment

```php
// config/config.php
return [
    'production' => true,  // Enable DI container compilation
    'debug' => false,
];
```

## 🧩 Event Sourcing Flow

1. **Command** arrives (e.g., SubmitPredictionCommand)
2. **Handler** loads aggregate from EventStore
3. **Domain logic** executes, generates events
4. **Events** appended to EventStore
5. **Projections** updated asynchronously
6. **Response** returned to client

### Event Types

- `prediction.submitted` - New prediction created
- `prediction.updated` - Prediction modified
- `prediction.evaluated` - Score calculated

## 📝 Code Quality

- **PSR-12** coding standard
- **PHPStan Level 8** static analysis
- **Strict types** enabled everywhere
- **Final classes** by default (inheritance by exception)
- **Immutable value objects**

## 🔍 Monitoring

Track projection health:
```sql
SELECT * FROM projection_state;
```

Check event processing lag:
```sql
SELECT 
    MAX(event_store_id) as latest_event,
    last_processed_position,
    (MAX(event_store_id) - last_processed_position) as lag
FROM event_store, projection_state
WHERE projection_name = 'prediction_read_model';
```

## 📄 License

MIT License (a `LICENSE` file has not been added to the repository yet).

## 👥 Contributing

1. Fork the repository
2. Create feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Open Pull Request

### Development Guidelines

- Write tests for all new features (run `composer test-coverage` to check coverage)
- Follow PSR-12 coding standards
- Add domain validation in Value Objects
- Use strict types everywhere
- Document complex business logic
- Update OpenAPI spec for new endpoints

## 🐛 Troubleshooting

### Database Connection Issues
```bash
# Test connection
php -r "new PDO('mysql:host=localhost;dbname=betting_game', 'user', 'pass');"
```

### Permission Denied
```bash
# Fix permissions
sudo chown -R www-data:www-data /var/www/betting-game
```

### Event Store Version Conflicts
Check for concurrent modifications:
```sql
SELECT * FROM event_stream WHERE stream_id = 'pred-uuid';
```

## 📞 Support

For issues and questions:
- GitHub Issues: [Create an issue]
- Documentation: [API Docs]
- Email: support@bettinggame.com
