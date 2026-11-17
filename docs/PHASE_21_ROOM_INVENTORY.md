# Phase 21 — Dated room blocks, physical assignment, and inventory availability

**Source:** backend `main`. **Release status:** code and regression tests committed; migration, full-suite execution, and real parallel MySQL/PostgreSQL booking tests still required before production use.

## Default hotel behavior

- A booking sells a **room type**; a named room may be assigned later by an administrator.
- **No overbooking**. Each registered physical room contributes one unit of type capacity unless it is covered by an **explicit active dated room block**.
- Blocks use half-open hotel-local nights `[checkIn, checkOut)` and require a reason. A block cannot overlap an already-assigned reservation or cause room-type occupancy to exceed remaining unblocked physical capacity.
- Bookings and blocks use the **same row lock on `room_types`** in transactions, so the writer checks capacity after acquiring that lock.
- Two active blocks for the same room cannot overlap. Released blocks no longer consume inventory.
- Assignment is limited to a confirmed reservation and a physical room of its own type/property, disallowing overlaps with active blocks and other consuming assigned reservations.
- Existing null/unknown reservation statuses conservatively consume inventory. Checkouts are exclusive. Manual changes bypassing the services violate the locking contract.
- Maintenance work orders **do not automatically become blocks**: `affects_room` has no effective date window. An admin must explicitly create a dated block, preventing ambiguous all-day/forever maintenance locks.
- Housekeeping readiness, arrival/check-in eligibility, money, rates, refunds and a public booking experience remain separate.

## API

`GET /api/v1/hotels/{hotel}/room-type-availability?roomTypeId=1&checkIn=2026-12-10&checkOut=2026-12-12`

Requires active hotel member and `hotel:read`; returns nightly `physicalRooms`, `reserved`, `blocked`, `remaining`, `overcommitted`, plus `canBook`. `canBook` means **room-type capacity at the moment queried**, not a guarantee: another booking or block may change capacity before creation. The booking writer always checks again under lock. The result does **not** indicate housekeeping readiness, a confirmed physical room, or a price.

`POST /api/v1/hotels/{hotel}/inventory-blocks`

```json
{
  "roomId": 1,
  "checkIn": "2026-12-10",
  "checkOut": "2026-12-12",
  "reason": "Air conditioner repair"
}
```

`POST /api/v1/hotels/{hotel}/inventory-blocks/{block}/release`

`PATCH /api/v1/hotels/{hotel}/bookings/{booking}/assign-room`

```json
{ "roomId": 1 }
```

All three writes require active hotel administrator membership plus `hotel:write`. The assignment is audited in `reservation_booking_events`. Block creation/release retain actor IDs and timestamps in `room_inventory_blocks`.

## Testing

```bash
git pull origin main
php artisan migrate
php artisan test --filter=RoomInventoryPhase21Test
php artisan test --filter=LiveReservationBookingTest
php artisan test
php artisan route:list --path=api
```

Do not run `migrate:fresh` on a production database.

### Production gates

- Run true **two-process last-room booking/block races** against MySQL/InnoDB and PostgreSQL, verifying only one operation succeeds.
- Verify existing catalog room-create/update paths honor the same lock before shrinking/changing physical inventory. Today the catalog and legacy direct DB writes can bypass the booking service. Do not claim global inventory race safety until this is addressed.
- Decide policy for maintenance and housekeeping signals, existing overcommitted data, and whether changing an already assigned room requires explicit transfer approval/audit.
- Verify indexes, foreign keys, rollback, same-property boundaries and error responses on both supported engines.
- The React mock booking wizard should remain unconnected until the date/room/rate contract is reconciled; do not display a speculative rate or imply that housekeeping has been checked.

## Next proposed work

Phase 22: catalogue-guard and allocation hardening; admin inventory-block history/listing; maintenance work-order date scheduling; booking lifecycle authorization for check-in/check-out; true MySQL/PostgreSQL concurrent workers and live frontend reservation-calendar integration.
