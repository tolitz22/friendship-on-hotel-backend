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

PHP >=8.3, Composer 2, required PHP extensions for Laravel. MySQL 8.4 LTS **or** PostgreSQL 16+ for normal development; SQLite extension for fast tests.

### Windows PowerShell quickstart

```powershell
git clone https://github.com/tolitz22/friendship-on-hotel-backend.git
cd friendship-on-hotel-backend
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Create a database named `friendship_on_hotel` in MySQL or PostgreSQL, then set `DB_CONNECTION=mysql` or `DB_CONNECTION=pgsql` and the matching credentials in `.env`. See [database setup](docs/DATABASE_COMPATIBILITY.md). Next:

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

Fast tests use SQLite `:memory:` via phpunit.xml; CI also exercises PostgreSQL and MySQL. Normal development supports either database.

The repository does not yet have a generated `composer.lock`; after a successful first installation, commit one for reproducible dependency resolution. GitHub Actions workflow: [Backend validation](.github/workflows/backend.yml).

## Frontend handoff

The React frontend's last completed milestone is **Hotel Settings & Configuration**. All major PMS screens are using mock adapters and light/dark UI. See [FRONTEND_HANDOFF.md](docs/FRONTEND_HANDOFF.md).

**Do not set `VITE_DATA_SOURCE=api` and expect the existing UI to work yet.** Backend routes are property-scoped, and the frontend currently expects different paths plus operational status fields the backend does not have. Integrate one screen at a time after designing selected-hotel context and the browser authentication strategy.

For business decisions still pending, read [BUSINESS_RULES.md](docs/BUSINESS_RULES.md). See [PHASE_1.md](docs/PHASE_1.md) for the exact scope and limitations.

## Backend Phase 2 — reservation foundations (no live bookings)

Phase 2 adds a schema for hotel-scoped reservation records, strict half-open date period semantics, pure known-room overlap detection, composite room/type integrity, and a read-only `GET /api/v1/hotels/{hotel}/reservation-records` endpoint. **It is not an availability or reservation creation engine.** Run `php artisan migrate` and `php artisan test` after pulling changes. See [Phase 2](docs/PHASE_2.md) and [reservation domain decisions](docs/RESERVATION_DOMAIN.md) before implementing booking writes.

## Dual database compatibility

Both **MySQL 8.4 LTS/InnoDB** and **PostgreSQL 16+** are supported targets. Set `DB_CONNECTION=mysql` or `pgsql` without changing application code. Reservation date CHECK constraints are enforced on both, and CI now includes a MySQL job. Read [DATABASE_COMPATIBILITY.md](docs/DATABASE_COMPATIBILITY.md) for engine-specific configuration, version requirements, database provisioning, and testing. Switching database engines does **not** automatically copy data.

## Backend Phase 3 — assigned-room conflict diagnostics

Added a protected read-only endpoint: `GET /api/v1/hotels/{hotel}/rooms/{room}/reservation-overlaps?from=YYYY-MM-DD&to=YYYY-MM-DD`. It reports known assigned reservation records and intersecting date pairs for a selected physical room. Results are **not availability or overbooking determinations**; unassigned stays, holds and operational rules are not evaluated. Both MySQL and PostgreSQL CI jobs now run the full PHP suite. See [Phase 3](docs/PHASE_3.md).

## Troubleshooting: `Class "Mockery" not found`

Laravel's feature test harness uses Mockery. If all feature tests stop with that same error and zero assertions, install project **development** dependencies; do not change individual tests or your MySQL data.

```powershell
git pull origin main
composer update mockery/mockery --with-all-dependencies
composer dump-autoload
php artisan test
```

Do not run `composer install --no-dev` in local testing or CI. If you already have a `composer.lock`, commit its intentional dependency changes once the test suite passes; do not commit `vendor/`. If additional failures appear after Mockery is available, inspect their first full stack trace separately.

## Backend Phase 4 — Guest Management

Added a hotel-scoped guest directory and validated profile create/update endpoints. Active hotel members can list/search/view; for this initial permissions model, only hotel admins with write-capable tokens may create or edit. No guest deletion endpoint or automatic reservation-to-guest linkage has been enabled. The frontend Guests page remains on mock data until its selected-hotel API routing and browser authentication are implemented. See [Guest Management Phase 4](docs/PHASE_4_GUESTS.md).

## Phase 5 — Frontend sign-in and live guest directory

The React frontend now supports developer-only in-memory Sanctum bearer sign-in, selected active hotel membership and a real Guests directory (search/read and admin create/edit). All other screens remain explicit previews. The backend's local CORS defaults include both `localhost:5173` and `127.0.0.1:5173`. Restart/clear config after changing `FRONTEND_ORIGINS` in the backend `.env`. Read [Phase 5 frontend integration](docs/PHASE_5_FRONTEND_INTEGRATION.md) and the frontend's `docs/PHASE_5_LARAVEL_INTEGRATION.md`.
