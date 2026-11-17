# Phase 23 — Reservation amendments, front-desk manifest and checkout turnover

Implemented on backend `main` (source changes; do not claim production-ready until tests and database concurrency verification pass).

## Initial policy

- Active property administrators with `hotel:write` may change **both stay dates** of a `confirmed` reservation **before the originally scheduled check-in date**, and may not set the new arrival in the past.
- Amendments preserve guest, room type, existing physical room assignment and status. They do not change the booking's price, because a rate/repricing engine is not implemented.
- The transaction obtains the same stable `room_types` lock used by booking and room blocks, checks **each** new night against room-type inventory, subtracts the booking's original consumption on overlapping nights, and rejects a blocked or conflicting assigned physical room.
- Every actual amendment records the prior/new dates in `reservation_date_amendments` plus an actor-attributed booking event. A no-op amendment is not audited again.
- Hotel members with read permission can query scoped **arrivals**, **departures** or **in-house** manifests for a calendar date. These lists are derived from stored booking state, not a projection of all expected events. Arrivals are confirmed bookings beginning that date, departures are checked-in bookings ending that date.
- Successful check-out automatically creates one `checkout_clean` housekeeping task and one booking-to-task link, plus the standard housekeeping task-created event, in the **same database transaction**. A repeated check-out remains HTTP 409 and cannot generate another turnover task.
- A turnover task is not evidence that a room is clean, inspected or bookable. Staff must still follow housekeeping processes.

## Routes

```http
GET   /api/v1/hotels/{hotel}/front-desk/manifest?date=2026-12-10&kind=arrivals&per_page=20
PATCH /api/v1/hotels/{hotel}/bookings/{booking}/dates
```

Example amendment:

```json
{"checkIn":"2026-12-11","checkOut":"2026-12-14"}
```

The manifest supports `arrivals`, `departures` and `in_house` and returns paging metadata. Date amendments require administrator write permission.

## Tables

- `reservation_date_amendments`: immutable date pairs, actor and property scope
- `reservation_turnover_links`: unique booking-to-checkout-clean-task mapping

## Verification

```bash
git pull origin main
php artisan migrate
php artisan test --filter=ReservationPhase23Test
php artisan test
php artisan route:list --path=api
```

Run against isolated test databases. No `migrate:fresh` on production. Repeat booking/block/amendment/assignment races using actual parallel workers on both MySQL 8.4/InnoDB and PostgreSQL 16+.

## Still not implemented

- Price recalculation, deposits, fees and refunds on date amendments
- Early departure / partial-night adjustments
- Room-type changes or guest transfers
- Guest identity verification, housekeeping room readiness, automatic maintenance blocks
- Frontend API adapter and live booking wizard integration

Do not enable customer-facing booking or assert financial correctness from this phase. The initial date amendment policy can be revised with the client's requested rules, along with tests and any additional snapshots.

## Proposed next phase

Phase 24: rate-plan and folio foundations, fees/deposits, and a read-only front-desk UI integration. Production booking rollout must await verified concurrency and operational readiness.
