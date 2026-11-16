# Laravel Backend Phase 1

## Implemented
- Laravel 12 bootstrap: artisan, public front controller, providers, config, app routes, Pest test infrastructure.
- Auth: staff user accounts, Sanctum bearer token login/logout, account activation flag, throttled login.
- Hotel membership: `hotel_memberships` with per-property role and active state. Only active members can read the selected property; only property admins with write capability can make catalog changes.
- Rooms: property, room types, physical rooms, hotel foreign keys and unique per-property room number.
- Property setup: contact details (nullable), room-type creation and room creation. No room removal or room-type reassignment flows yet.
- Initial operator commands: `hotel:create`, `hotel:bootstrap-admin`.
- GitHub Actions workflow: Composer install, PHP lint, route registration, feature tests.

## Scope of real API

Public:
- `GET /api/v1/health`
- `POST /api/v1/auth/login` (throttled; response includes bearer token)

Authenticated:
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/logout`
- `GET /api/v1/hotels`

Active property membership required:
- `GET /api/v1/hotels/{hotel}`
- `GET /api/v1/hotels/{hotel}/room-types`
- `GET /api/v1/hotels/{hotel}/rooms?page=1&per_page=20`

Active property admin with write-capable token required:
- `PATCH /api/v1/hotels/{hotel}` (name/address/contactEmail/contactPhone only)
- `POST /api/v1/hotels/{hotel}/room-types` (name, maxOccupancy)
- `POST /api/v1/hotels/{hotel}/rooms` (roomTypeId, number, floor)

## Not built
No reservation management, guest profile records, room occupancy statuses, housekeeping state changes, maintenance blocks, rate plans, bills, tax rules, refunds, deposits, real room availability, or night audit.

The creation endpoints are strictly room **catalog configuration**. They do not allocate or sell room nights.

## Security notes
- Users are not publicly registered. Provision the first property and administrator from a trusted server console; protect console access.
- Treat bearer tokens as secrets; do not store them in browser localStorage for a future SPA. Prefer Sanctum same-origin/cookie authentication or a secure server-side session design for frontend integration.
- Token abilities and hotel roles are both checked for writes, and a user's property access is rechecked on every request.
- HTTPS is mandatory for production, including the login endpoint.
- A permission label in the existing frontend Settings mock is not a real Laravel role permission; only admin vs other active membership is currently enforced.
- Store real authorization decisions and audit logs in backend in future phases.

## Tests
`tests/Feature/AuthenticationTest.php`, `HotelIsolationTest.php`, `HotelAdministrationTest.php`, `HealthEndpointTest.php`.

The repository currently has no generated Composer lockfile. CI installs dependencies using composer.json. Generate and commit composer.lock after first successful Composer install to stabilize dependencies.

This implementation has not been verified by a local Composer/PHP test run in the assistant's environment; use CI/local test results before deployment.
