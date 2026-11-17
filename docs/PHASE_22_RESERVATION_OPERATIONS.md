# Phase 22 — Reservation operations and inventory hardening

**Repository:** backend `main`. This milestone adds narrow front-desk transitions and inventory administration. PHP/Composer and multi-process database verification are **not yet confirmed**.

## Operational defaults

1. Staff with an active **admin** membership and `hotel:write` may check in a `confirmed` reservation **only when an assigned physical room exists**.
2. Check-in may occur on or after the hotel's planned arrival date and strictly **before** planned checkout. Date comparisons use the hotel's configured timezone, falling back to `Asia/Manila`.
3. Checkout requires `checked_in` and cannot occur before the planned checkout date. An early departure requires a future reservation amendment/repricing workflow rather than silently freeing future room-nights.
4. State transitions are `confirmed -> checked_in -> checked_out`. Each change is transactionally audited with the acting staff ID. Repeating or skipping a transition returns HTTP 409.
5. Check-in rechecks overlapping assigned-room reservations and active dated blocks. It **does not certify room cleanliness or guest identity**, which require separate front-desk and housekeeping policies.
6. Room catalogue creation shares the room-type row lock used for booking and blocks. Changing a physical room's type locks involved room types in ascending ID order and is rejected if there are any historical inventory blocks on the room or existing reservation records on source/destination types.
7. Inventory block list is admin-only and paginated. It includes active/released status, dates, reason, creation/release actor IDs and timestamps. Creation and release are already supported by Phase 21.

## New routes

```http
POST /api/v1/hotels/{hotel}/bookings/{booking}/check-in
POST /api/v1/hotels/{hotel}/bookings/{booking}/check-out
GET  /api/v1/hotels/{hotel}/inventory-blocks?active=1&roomId=1&per_page=20
```

These are hotel-scoped, authenticated **administrator-only** endpoints. They are not public guest check-in/check-out routes.

## Tests

`tests/Feature/ReservationOperationsPhase22Test.php` exercises:
- required physical assignment;
- audit and supported transitions;
- hotel-local arrival and checkout dates;
- rejecting cancelled stays and cross-property access;
- active/released block listing;
- refusal of historical-block room-type changes;
- staff authorization.

Run on an **isolated test database**:

```bash
git pull origin main
php artisan migrate
php artisan test --filter=ReservationOperationsPhase22Test
php artisan test --filter=RoomInventoryPhase21Test
php artisan test
php artisan route:list --path=api
```

Do not use `migrate:fresh` on a hotel database with actual records.

## Known limitations and production gates

- Hotel inventory is a per-room-type ledger. Changes made directly in SQL or through other paths bypass its service locking and are not protected.
- An `affects_room` maintenance work order is **not** an automatically dated inventory block. The manager must explicitly create/release dated blocks.
- Existing booked room types, physical-room assignment and block operations have not had **two genuinely simultaneous last-room booking tests** executed against both MySQL/InnoDB and PostgreSQL. SQLite tests alone are insufficient for production concurrency certification.
- There is no actual guest identity verification, signature capture, check-in time record, staff cash handling, invoice, deposit, early departure amendment, no-show transition, housekeeping turnover or financial settlement.
- Reservation completion does not automatically create housekeeping turnover tasks or guarantee room cleanliness. The frontend's mock booking UI remains separate.
- Booking and cancellation service policies are conservative placeholders; hotel-specific operational approval and versioning should precede any customer launch.

## Next milestone

Phase 23 — reservation amendments, front-desk manifest and housekeeping turnover state, followed by a frontend API contract migration. Before any live launch, implement and execute concurrent workers on both supported database engines, and reconcile room availability, guest operations and financial liability.
