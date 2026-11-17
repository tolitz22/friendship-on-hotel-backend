# Phase 7 — Admin catalogue creation frontend integration

The React frontend now calls the **existing** Laravel APIs for administrator-only room-type and physical-room creation, without adding new tables:

- `POST /api/v1/hotels/{hotel}/room-types` with `{name,maxOccupancy}`
- `POST /api/v1/hotels/{hotel}/rooms` with `{roomTypeId,number,floor}`

Server-side `hotel.member` and `hotel.admin` middleware remain authoritative. Active hotel admins with a read/write-capable Sanctum token may write. Other staff, disabled users, inactive memberships, and users lacking token abilities must not write.

The existing Laravel validators enforce type/room unique-per-property names, max occupancy, optional floor and within-property room-type association. MySQL and PostgreSQL enforce uniqueness with scoped indexes as well. No reservation, hold or availability is created from a room record.

`tests/Feature/CatalogAdministrationTest.php` adds positive write and negative authorization/validation tests. CI already exercises the entire suite on SQLite, MySQL and PostgreSQL.

The frontend invalidates live room, type and catalogue-summary TanStack caches after success, so the dashboard room count and Settings totals refresh. Its UI fields and API errors are documented in frontend `docs/PHASE_7_CATALOG_ADMIN.md`.

**Still out of scope:** room/type edit or delete, staff operational roles, audit trail for catalogue writes, booking engine, physical occupancy, housekeeping and finance.
