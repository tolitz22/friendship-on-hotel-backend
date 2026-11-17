# Phase 19 — Reservation Booking Engine Foundation (design and pure diagnostics)

Status: **foundation implemented on a feature branch; operational policies NOT approved; no live booking routes**.

## Delivered

- `ReservationLifecycleBlueprint` accepts an explicit state list, directed transitions and a list of states assumed to consume inventory. There are **no production defaults**. Its helpers describe a hypothetical plan; they do not authorize a state transition.
- `ReservationNightDemandProjection` produces date-by-date record IDs for assigned rooms and unassigned room-type demand, using hotel-local checkout-exclusive nights and the blueprint's **caller-supplied assumptions**. Every output explicitly states `diagnosticOnly: true`, `isAvailability: false`, and `canBook: false`.
- Pest regression coverage for missing/invalid plan definitions, disallowed transitions, status filtering, nights and non-availability.
- No changes to routes, migrations, frontend adapters, permissions, or existing reservation records.

## Owner decisions that block activation

A saved policy draft is not approval. Obtain signed-off decisions for each of the following **before** introducing reservation writes:

1. Sellable unit: room type, assigned physical room, or a precisely defined hybrid.
2. Physical room assignment timing and what guests are promised.
3. Reservation statuses, permitted transitions, terminal states, no-show and which states consume room-night inventory.
4. Pending-hold expiration duration, clock source, expiration worker and cleanup behavior.
5. Maintenance/manual blocks, effective hotel-local nights, overrides and release.
6. Capacity and overbooking limits, actors authorized to override and mandatory audit reasons.
7. Cancellation/amendment cutoffs, fees, refunds, inventory restoration and repricing.
8. Rates, deposits, taxes, discounts, currency, rounding and immutable quote snapshots.
9. Hotel timezone, check-in/out boundaries, night audit, idempotency and audit retention.
10. Guest linkage, cross-hotel isolation, permission model and acceptance criteria.

Until those decisions are approved, do not persist these hypothetical lifecycle examples as active hotel policies or return a bookable result from diagnostics.

## Transactional booking design for Phase 20 (NOT implemented)

1. Authenticate and authorize the active hotel member for the exact hotel. Validate guest, room type, stay period, selected unit, and policy revision.
2. Require a scoped idempotency key with a request fingerprint. Identical retries return the original result; a changed payload with the same key returns a conflict.
3. Start a transaction. Lock a stable **hotel + room type** row (or dedicated inventory-lock row) using `SELECT ... FOR UPDATE` on **both** PostgreSQL and MySQL/InnoDB. Lock resources in consistent order for multi-type stays.
4. Re-read the approved policy, expiring holds and inventory for **every requested night** under that lock. Include active allocations, unassigned type bookings and effective maintenance blocks. A physical-room assignment must satisfy the same-hotel/same-type composite constraints.
5. Atomically create reservation, per-night allocation/hold rows, revision reference, event audit and idempotency result. If any night is exhausted, roll back completely and return a defined 409 response, never a partial booking.
6. Use unique keys for idempotency and for each per-night allocation identity. Do not rely solely on an overlap query or `lockForUpdate()` over a missing row: absent rows provide no portable locking guarantee.
7. On amendments or cancellations, acquire the same canonical locks and atomically release/reallocate consumption, with explicit transition authorization and full audit.
8. Do not produce a price quote until rate/tax rules are approved and an immutable pricing snapshot can be stored.

This is an architectural proposal, not evidence of a working race-safe booking engine.

## Verification and go-live gates

```bash
php artisan test --filter=ReservationEngineFoundationTest
php artisan test
php artisan route:list --path=api
```

Later, run actual parallel **last-room** booking requests (and idempotent retries, concurrent cancellation/amendment, overlapping nights, maintenance blocks, and tenant isolation) on both **MySQL 8.4/InnoDB and PostgreSQL 16+**. SQLite tests cannot establish concurrency correctness. Test a failure halfway through an allocation to confirm the entire transaction rolls back.

Current readiness flags must remain `approved: false`, `readyForImplementation: false` and `readyForBookings: false`. The React booking wizard remains in mock mode.

## Phase 20 exit conditions

Policy approval recorded and versioned; atomic inventory allocator implemented; multi-engine concurrency tests green; secure hotel-scoped reservation mutation APIs, idempotency and auditing verified; no invented financial/room-readiness semantics; frontend integration gated by verified API contracts.
