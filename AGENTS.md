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

## Reservation Phase 2

Review `docs/RESERVATION_DOMAIN.md` and `docs/PHASE_2.md`. `StayPeriod` uses half-open nights. `RoomOverlapDetector` only identifies conflicts among given physical-room records; it never determines availability or enforces inventory. Reservation records have **no write routes** and an intentionally unsettled state machine. Do not introduce booking mutations, inventory locks or cancellation rules until the hotel has approved the inventory unit and lifecycle.

## Cross-engine database compatibility

MySQL 8.4 and PostgreSQL 16+ are both supported targets. Read `docs/DATABASE_COMPATIBILITY.md`. Do not add a PostgreSQL-only migration or inventory SQL without adding a MySQL-equivalent implementation and tests. Protect reservation room/type composite constraints, valid positive date ranges, and tenant scoping across both databases. Use SQLite only as fast test support, not a substitute for production-engine verification.

## Backend Phase 3

Read `docs/PHASE_3.md`. The room-conflict diagnostic is scoped to one physical room and supplied date window. It is read-only and explicitly not an availability function. Do not let the absence of collision pairs trigger a booking, room release, price quote, or check-in eligibility decision. Preserve both MySQL and PostgreSQL regression coverage.

## Guest Management Phase 4

Read `docs/PHASE_4_GUESTS.md` before expanding guest profiles. Guests are property-scoped personal data, not globally shared contacts. Current authorization: active hotel members can read; active hotel admins with `hotel:write` can create/update. No guest delete, merging or reservation-link workflow without explicit policy, audit and retention requirements. Keep both MySQL and PostgreSQL tests and protect personal information.

## Phase 5 authentication and browser integration

Preserve property-bound Sanctum authorization. Frontend Phase 5 uses a temporary in-memory bearer token only; production SPA auth design is still pending. The real Guests API must never silently use mock data on network/authorization failures. Do not grant write permissions based on frontend role labels. See `docs/PHASE_5_FRONTEND_INTEGRATION.md`.

## Phase 8 catalogue editing

Read `docs/PHASE_8_CATALOG_EDITS.md`. Room/type edit PATCH calls must use the selected hotel's relationships, admin role and write token, transactionally audit actual changes, and block structural changes touching referenced reservation inventory. Never add a room type reassignment or max-occupancy override without explicit booking policy approval. Keep MySQL and PostgreSQL parity. No delete endpoint.


## Phase 20 — Initial live reservation writes

Read `docs/PHASE_20_LIVE_RESERVATIONS.md` before changing bookings. The Phase 1–19 prohibition on booking writes was superseded **only for the narrow, admin-only Phase 20 endpoints** `POST /bookings` and `POST /bookings/{booking}/cancel`. The initial policy is room-type selling, optional deferred assignment, confirmed-at-creation, no overbooking, no pricing or holds; all mutations must use `ReservationBookingService` and stable room-type locks. An unknown/nullable legacy lifecycle consumes capacity until reconciled. Do not enable front desk/public booking, availability claims, automatic room assignment, maintenance blocks, payment collection or frontend live-booking mode without implementing their full policies and tests.

Production use is gated on a green Laravel suite and parallel last-room tests on **both MySQL/InnoDB and PostgreSQL**, plus maintenance/capacity review. SQLite tests alone do not verify inventory concurrency. Client-specific policy changes require versioning and relevant code/schema changes, not silent config toggles.


## Phase 21 — Live room-type inventory and explicit dated blocks

Read `docs/PHASE_21_ROOM_INVENTORY.md` before editing inventory behavior. `RoomInventoryService` owns nightly availability, dated physical-room inventory blocks, and confirmed reservation room assignment. Booking creation rechecks availability while holding the same room-type lock. Do not treat work-order `affects_room` or housekeeping completion as automatic inventory status; those records currently have no approved effective-night rules. Admin only for blocks, release, and room assignment. Production concurrency and catalogue shrink safeguards remain unverified.


## Phase 22 — Reservation operations and catalogue locking

Read `docs/PHASE_22_RESERVATION_OPERATIONS.md`. New admin-only check-in/check-out transitions are audited and constrained by planned dates in hotel-local time; check-in requires a physical room assignment. Admin inventory-block listing supports reviewing active and released records. Catalog room creation and physical room type edits must serialize with reservation inventory locks. Do not infer housekeeping readiness, billing settlement or identity verification from a reservation lifecycle state. Parallel MySQL/PostgreSQL booking-and-block concurrency verification remains a production gate.


## Phase 23 — Reservation date amendments and turnover

Read `docs/PHASE_23_RESERVATION_AMENDMENTS.md`. Date amendments are admin-only, confirmed and pre-arrival, share room-type locks, check all amended nights, preserve physical room assignment and write immutable date audit records. Front-desk manifests are read-only and hotel-scoped. Successful checkout creates exactly one `checkout_clean` housekeeping task in the checkout transaction. No automatic room readiness, rate repricing or early-departure financial adjustment is implied. The frontend booking wizard remains disconnected pending API contract and concurrency validation.


## Phase 24 — Room rate snapshots and unpriced legacy compatibility

Read `docs/PHASE_24_FINANCE_FOUNDATION.md`. Hotel administrators may create fixed nightly `room_rate_plans`; optionally priced bookings snapshot one room-night folio entry per night atomically within booking creation. Amounts are integer minor units and quote only room subtotal; never imply tax, invoice, payment, fee or deposit calculation. Unpriced legacy booking creation still works. Priced bookings cannot be amended or cancelled with the old unfunded/no-reversal routes. Before live financial use, implement approved refund/reversal, tax and payment settlement policies plus MySQL/PostgreSQL integration tests.


## Phase 25 — Provisional Folio Ledger and Payment Evidence

Read `docs/PHASE_25_PROVISIONAL_FINANCE_LEDGER.md`. Folio adjustments and manual payment evidence are hotel-scoped, admin-only writes with idempotency keys and actor/reviewer audit. Bank-transfer evidence requires an independent staff confirmer; confirmation remains **staff attestation, not bank verification**. Ledger differences are not tax-complete payable balances, official receipts or settlement. Priced cancellations via `/cancel-priced-unpaid` require no payment evidence and no manual adjustments, then atomically credit all room-night charges before cancelling. Never assume this issues a refund. Preserve room-type/folio transaction locks and the guard on priced date amendments. Production money operations require external verification and finance-policy approval.
