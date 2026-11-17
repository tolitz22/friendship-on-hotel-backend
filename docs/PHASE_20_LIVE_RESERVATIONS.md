# Phase 20 — Initial Live Reservations (conservative default policy)

**Status:** Backend endpoints implemented on `main`. Requires migration, green tests and production-engine concurrency verification before use with real guests. The user authorized choosing initial standard defaults; hotel-specific policies can be revised later. **Do not claim operational launch-readiness yet.**

## Initial operational defaults (not universal industry requirements)

- Sell by **room type**, backed by the number of registered physical rooms in that type.
- Physical room assignment may occur later; a new booking has no assigned room.
- Initial bookings are created directly as **confirmed**; there is no pending payment hold or integrated rate quote.
- **No overbooking**. An atomic room-type row lock serializes booking creation and cancellation for that room type.
- A confirmed reservation consumes each night in `[checkIn, checkOut)`; checkout day is excluded.
- The writer treats **unknown or null legacy statuses as consuming inventory**. By default only `cancelled`, `checked_out`, and `no_show` are released states, configurable in `config/booking.php`. Operators must reconcile unknown legacy data.
- Cancel is admin-only and only from `confirmed`; cancellations are audited. There is **no automated refund**, fee calculation or cancellation time cutoff.
- All writes require authenticated, active hotel administrator membership with `hotel:write`.
- Every booking requires an existing guest belonging to the same hotel.
- Max stay: 31 nights.
- **No** automatic room assignment, room availability advertised to guests, maintenance blocking, payment/deposit collection, price quote, public booking portal, or frontend live-booking integration.

These decisions are **starter implementation defaults**, not permanent client policy or legal terms. Changes to inventory unit, cancellation terms and statuses need corresponding migrations, code and regression tests; do not treat every default as a runtime-only toggle.

## API

`POST /api/v1/hotels/{hotel}/bookings` (admin only; 201 new, 200 exact replay, 409 conflict, 422 invalid request)

```json
{
  "idempotencyKey": "unique-request-key-123",
  "guestId": 1,
  "roomTypeId": 1,
  "checkIn": "2026-12-01",
  "checkOut": "2026-12-03"
}
```

Returns `data.id`, `reference`, `hotelId`, `guestId`, `roomTypeId`, `assignedRoomId`, `checkIn`, `checkOut`, `status` and `idempotentReplay`. Same key+payload within a property replays. Same key+different payload conflicts.

`POST /api/v1/hotels/{hotel}/bookings/{booking}/cancel` (admin only; 200 on success, 404 other property, 409 invalid state).

The existing read-only `GET /reservation-records` and legacy non-writable `POST /reservation-records` behavior remain untouched.

## Inventory transaction

The service takes `SELECT FOR UPDATE` on a stable, scoped room-type row inside a transaction. It rechecks all requested nights and writes the reservation, idempotency record and event atomically. Each room type is locked independently; creating reservations for distinct types does not require global locking.

**Crucial limitations:**

1. Direct database writes or legacy reservation mutations that bypass `ReservationBookingService` do not acquire its lock. Prevent these writes in deployed systems.
2. Current room counts are catalogue counts, **not room-ready/maintenance-adjusted sellable capacity**; maintenance and manual inventory blocks are not integrated. This can lead to operational overcommitment if registered rooms are unavailable.
3. SQLite tests cannot prove cross-process race safety on MySQL/PostgreSQL. The hotel must run two truly parallel requests for the last room night against **both supported engines**, verify one succeeds and one conflicts, and verify cancellation/retry races.
4. Room inventory and active bookings must be reconciled when catalogue room counts change. Existing catalogue edit guards do not implement a complete inventory shrink/move policy.
5. Existing recorded states such as `checked_in` are counted, but this milestone does not implement check-in/out state transitions or staff room assignment.
6. The idempotency replay reports the reservation's **current** state (e.g., `cancelled` after a later cancellation), not a stored immutable copy of the original creation response.

## Verification before operational activation

```bash
php artisan migrate
php artisan test --filter=LiveReservationBookingTest
php artisan test
php artisan route:list --path=api
```

Run all tests against a **test-only** DB. Verify migration rollback, unique idempotency scope, foreign-key integrity, no cross-property access, overlapping-night conflicts, adjacent checkouts, cancellation and retry behavior. Repeat last-room concurrency integration tests on MySQL/InnoDB and PostgreSQL. Review and activate production-appropriate hotel policies before exposing this endpoint via React API mode.

## Next milestone

Phase 21: hotel-specific versioned booking policy overrides, room assignment, maintenance room-night blocks, and a true availability/readiness API; only then migrate the frontend calendar/booking wizard from mock to supported live contracts.
