# Backend Phase 2 — Reservation Data & Domain Foundations

**Delivered scope**
1. Date-only `StayPeriod` and pure `RoomOverlapDetector` with unit tests
2. New `reservation_records` structural table with valid-date and hotel/type constraints
3. `ReservationRecord` model and `Hotel::reservationRecords()` relation
4. Tenant-scoped, authenticated, read-only `GET /api/v1/hotels/{hotel}/reservation-records`
5. Database and access tests, and a reservation-policy decision matrix

**Explicit non-goals:** No live booking create/cancel/edit, inventory sales, availability claims, holds, waitlist, automatic room allocation, financial charges, front desk state transitions, or guest PII.

**Next milestone after validation:** Choose and document the business rules in `docs/RESERVATION_DOMAIN.md`; then design the transactional inventory engine. MySQL/InnoDB and PostgreSQL integration tests must each include simultaneous requests targeting the last available room night. Update the frontend API adapter only after its property-scoped auth and response contract is reconciled.

**Verification:** Published source files are not equivalent to passing CI. Run Composer, migrations, unit tests and feature tests in a configured environment. SQLite tests verify structural behavior, but real inventory-concurrency behavior must be validated on both MySQL/InnoDB and PostgreSQL.

Dual database support is configured for MySQL 8.4 and PostgreSQL 16+. See [DATABASE_COMPATIBILITY.md](DATABASE_COMPATIBILITY.md) for the engine-specific schema, CI, and local configuration.
