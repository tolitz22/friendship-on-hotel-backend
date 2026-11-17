# Phase 8 — Audited, guarded catalogue updates

The backend introduces `catalog_audit_logs` and an administrator-only `CatalogEditController` for editing previously created room types and physical rooms. This is a **catalogue-management** milestone, not a reservation engine.

## Migration

`2026_11_17_000003_create_catalog_audit_logs_table.php` creates:
- `hotel_id` foreign key to the property
- `actor_id` foreign key to staff users
- `subject_type` string and `subject_id` integer
- `changes_before`, `changes_after` JSON
- `created_at` and `catalog_audit_subject_idx`

This table is portable to PostgreSQL 16+ and MySQL 8.4 LTS/InnoDB. JSON values contain **catalogue metadata only**; no guest profiles, credentials, payment data or notes.

## API

```http
PATCH /api/v1/hotels/{hotel}/room-types/{type}
PATCH /api/v1/hotels/{hotel}/rooms/{room}
GET /api/v1/hotels/{hotel}/catalog-audit?subjectType=room&subjectId=1
```

All endpoints are within the existing `auth:sanctum`, `active.user`, `hotel.member` and `hotel.admin` middleware. Only active hotel admins holding `hotel:read` and `hotel:write` abilities are authorized. The target record is found via its `$hotel` Eloquent relationship, so a foreign hotel's room/type returns 404. Audit requests also check subject ownership.

Edit operations run in `DB::transaction`, row-lock the target via `lockForUpdate()`, update it and insert a before/after audit row atomically. A no-op update produces no event.

### Safety policy (intentionally conservative)

- Rename a room type even with reservation references; audit the change.
- Change a room type's `maxOccupancy` only when that type has **no** reservation records.
- Change a room's number/floor, retaining before/after history.
- Reassign a physical room to a different type **only if** there are no records referring to that physical room **or** its current room type.
- Validate the destination type is in the selected hotel.
- Reject duplicates via per-hotel scoped `unique` validation and indexes.
- No room or room-type deletion; no occupancy, hold, rates, inventory availability or booking write APIs.

These checks are **not a guarantee of race-free reservation allocation** once booking writes are introduced. Future booking transactions must coordinate with the same rows/locking policy. This phase does not infer any inventory-consuming lifecycle states.

## Tests

`tests/Feature/CatalogEditTest.php`: update history, no-op, reservation-linked 422 responses, safe reassignment, foreign-hotel 404, duplicate validation and non-admin 403.

Run `php artisan migrate` **before** using the new endpoints. Run `php artisan test` and check both MySQL and PostgreSQL CI runs before considering this milestone verified in production.

See frontend `docs/PHASE_8_CATALOG_EDITS.md` for UI integration and cache invalidation.
