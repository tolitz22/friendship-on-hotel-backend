# Friendship On Hotel — Backend Development Instructions

Independent repository: `tolitz22/friendship-on-hotel-backend`.
Frontend: `tolitz22/friendship-on-hotel-frontend`.

Read `docs/BUSINESS_RULES.md`, `docs/ARCHITECTURE.md`, and `docs/FRONTEND_HANDOFF.md` before implementing features.

## Phase 1 baseline
- Laravel 12, PHP >=8.3, PostgreSQL in development, SQLite in tests.
- Sanctum Bearer token API auth, active user and active hotel membership checks.
- Read hotel, room types, physical rooms; admin-only update contact fields and create room type/room.
- Hotel-specific access always enforced on the server. Never trust client-provided hotel IDs alone.
- Explicit DTOs; keep read-only inventory conditions distinct from availability.
- CLI-only bootstrap admin. Do not add default staff credentials or public registration.

## Non-goals
- Do not invent booking unit, cancellation, check-in, billing, room holds or tax policies.
- Do not implement reservation creation/cancellation, inventory allocations, real rates, payments, or folios without policy approval.
- Do not automatically infer occupancy, maintenance or housekeeping readiness from physical-room master data.
- Do not modify the frontend source in this milestone. Its mock adapters remain authoritative for UI development until API contracts are reconciled.

## Quality and security
- Validate, authorize and scope writes; add regression tests for cross-hotel requests.
- Use transactions for multi-write domain operations, and test conflicting reservations once booking rules exist.
- Run `composer install`, `php artisan migrate`, `php artisan test`, PHP lint and GitHub CI.
- Never commit production secrets or real guest records.
- Don't claim a full build/test pass unless verified in a PHP/Composer environment.
