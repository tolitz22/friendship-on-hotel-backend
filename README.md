# Friendship On Hotel PMS — Laravel Backend

Laravel 12 / PHP 8.3+ API foundation. Separate React + Vite frontend: [friendship-on-hotel-frontend](https://github.com/tolitz22/friendship-on-hotel-frontend).

## Backend Phase 1
- Runnable Laravel application skeleton prepared for Composer installation.
- Sanctum bearer-token login and logout with expiring tokens, staff activation and active property membership checks.
- Hotel/property catalogue, room-type and physical-room models, migrations, and property-scoped read APIs.
- Admin-only property contact update and room/room-type creation.
- Tests for authentication, membership isolation, cross-property room types, and role-restricted writes.
- GitHub Actions configuration for dependency install, linting, route list and feature tests.

**Not implemented:** any real reservation lifecycle, occupancy status, room-night availability, housekeeping transitions, maintenance blocks, price calculation, guest folio, payment processing, or refund logic.

## Requirements

PHP >=8.3, Composer 2, required PHP extensions for Laravel. PostgreSQL for normal development; SQLite extension for tests.

### Windows PowerShell quickstart

```powershell
git clone https://github.com/tolitz22/friendship-on-hotel-backend.git
cd friendship-on-hotel-backend
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Create a PostgreSQL database named `friendship_on_hotel` (or update the values in `.env`), then:

```powershell
php artisan migrate
php artisan hotel:create "Friendship On Hotel"
```

The command prints a property ID. **Use that actual ID** to add a first administrator from a trusted console:

```powershell
php artisan hotel:bootstrap-admin <property-id> admin@example.com
```

The command securely prompts for the password and staff name for a new user. Use a real admin email; never commit credentials or seed passwords.

Run the API:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Health check: `GET http://127.0.0.1:8000/api/v1/health`.

Login:

```http
POST /api/v1/auth/login
Content-Type: application/json

{"email":"admin@example.com","password":"<your-password>"}
```

The response contains a **secret** `data.token`. Use `Authorization: Bearer <token>` on protected endpoints. Store it securely. Do not paste it into logs or save it in a browser localStorage. For a production browser SPA, design Sanctum cookie-based authentication separately before integration.

## Initial API

| Method | Endpoint | Access |
| --- | --- | --- |
| GET | `/api/v1/health` | Public |
| POST | `/api/v1/auth/login` | Throttled |
| GET | `/api/v1/auth/me` | Authenticated active staff |
| POST | `/api/v1/auth/logout` | Authenticated active staff |
| GET | `/api/v1/hotels` | Authenticated active staff |
| GET | `/api/v1/hotels/{hotel}` | Active member |
| GET | `/api/v1/hotels/{hotel}/room-types` | Active member |
| GET | `/api/v1/hotels/{hotel}/rooms` | Active member |
| PATCH | `/api/v1/hotels/{hotel}` | Active property admin + write token |
| POST | `/api/v1/hotels/{hotel}/room-types` | Active property admin + write token |
| POST | `/api/v1/hotels/{hotel}/rooms` | Active property admin + write token |

Roles are only an initial `admin` / non-admin read/write distinction. Future fine-grained Laravel policies are not yet built.

## Quality checks

```powershell
php artisan route:list --path=api
php artisan test
vendor/bin/pint --test
```

Tests use SQLite `:memory:` via phpunit.xml; normal development uses PostgreSQL.

The repository does not yet have a generated `composer.lock`; after a successful first installation, commit one for reproducible dependency resolution. GitHub Actions workflow: [Backend validation](.github/workflows/backend.yml).

## Frontend handoff

The React frontend's last completed milestone is **Hotel Settings & Configuration**. All major PMS screens are using mock adapters and light/dark UI. See [FRONTEND_HANDOFF.md](docs/FRONTEND_HANDOFF.md).

**Do not set `VITE_DATA_SOURCE=api` and expect the existing UI to work yet.** Backend routes are property-scoped, and the frontend currently expects different paths plus operational status fields the backend does not have. Integrate one screen at a time after designing selected-hotel context and the browser authentication strategy.

For business decisions still pending, read [BUSINESS_RULES.md](docs/BUSINESS_RULES.md). See [PHASE_1.md](docs/PHASE_1.md) for the exact scope and limitations.

## Backend Phase 2 — reservation foundations (no live bookings)

Phase 2 adds a schema for hotel-scoped reservation records, strict half-open date period semantics, pure known-room overlap detection, composite room/type integrity, and a read-only `GET /api/v1/hotels/{hotel}/reservation-records` endpoint. **It is not an availability or reservation creation engine.** Run `php artisan migrate` and `php artisan test` after pulling changes. See [Phase 2](docs/PHASE_2.md) and [reservation domain decisions](docs/RESERVATION_DOMAIN.md) before implementing booking writes.
