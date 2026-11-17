# Database compatibility — MySQL + PostgreSQL

Friendship On Hotel PMS supports a **selectable database engine**, not two databases in one running application. Use `DB_CONNECTION=mysql` or `DB_CONNECTION=pgsql` in the deployment's `.env`. Laravel Eloquent, API responses, and the frontend stay unchanged.

## Supported versions

- PostgreSQL 16+ is the production-target PostgreSQL configuration verified by CI when passing.
- MySQL 8.4 LTS (InnoDB) is the supported MySQL CI target.
- SQLite is used for fast in-memory unit/feature tests, not the intended production engine.

**Do not use older MySQL versions than 8.0.16:** earlier versions parse but do not enforce CHECK constraints. MySQL 8.4 is recommended for this project's compatibility target.

## PostgreSQL local development

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=friendship_on_hotel
DB_USERNAME=postgres
DB_PASSWORD=your_local_password
```

PHP requires `pdo_pgsql`, and you must create the configured database/user with appropriate permissions.

## MySQL local development

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=friendship_on_hotel
DB_USERNAME=hotel_user
DB_PASSWORD=your_local_password
```

PHP requires `pdo_mysql`. Create the database with `utf8mb4` and grant the app user access. Don't use a production MySQL root account.

```sql
CREATE DATABASE friendship_on_hotel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hotel_user'@'localhost' IDENTIFIED BY 'replace_with_a_secure_password';
GRANT ALL PRIVILEGES ON friendship_on_hotel.* TO 'hotel_user'@'localhost';
```

Depending on your MySQL host/network, `'hotel_user'@'localhost'` and `'hotel_user'@'127.0.0.1'` can be treated differently. Align the account host with your environment.

## Switching databases

A database switch **does not migrate existing hotel data** across engines. Back up and migrate data explicitly; do not point an existing production installation at an empty database without a migration plan.

For a fresh installation on either engine:

```bash
php artisan config:clear
php artisan migrate
php artisan test
```

The test suite runs on a separately configured test database. Do not use a live production database for tests.

## Portable schema policy

- Laravel schema builder covers core tables, indexes, foreign keys and constraints.
- `reservation_records` uses an enforced `CHECK (check_out > check_in)` on MySQL 8.4 and PostgreSQL; SQLite uses equivalent insert/update triggers for tests.
- Composite foreign keys ensure the assigned physical room has the same hotel AND room type as the reservation record.
- IDs are integer keys but API IDs are stringified; don't assume database-specific UUID functions.
- Date-only stays are half-open `[check_in,check_out)`; datetime timestamps retain timezone handling at the application boundary.
- No PostgreSQL-only range types or exclusion constraints are used in the current schema. Future inventory concurrency design MUST be validated on MySQL/InnoDB and PostgreSQL separately.
- Database checks prevent invalid date ordering, **not** overlapping hotel bookings.
- Avoid vendor-specific SQL in business services unless conditional support and tests exist for both drivers.

## CI

`.github/workflows/backend.yml` now runs SQLite feature tests plus PostgreSQL and MySQL jobs. MySQL runs the full test suite; PostgreSQL runs the reservation schema test file. The jobs must actually pass before claiming runtime parity.

## Frontend contract

The React/Vite frontend remains in mock mode and is database-agnostic. Its last completed frontend milestone is Settings. Read `docs/FRONTEND_HANDOFF.md`; do not switch `VITE_DATA_SOURCE=api` until HTTP routes and DTOs are reconciled.
