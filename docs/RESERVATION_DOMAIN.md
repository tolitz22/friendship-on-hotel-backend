# Reservation domain foundation — Phase 2

Status: **schema and analytical read model only; not a booking engine**.

The hotel has **not approved** the core inventory unit (room type vs physical room vs hybrid), assignment timing, inventory holds, overbooking policy, cancellation transitions, or finance rules. These choices remain open. Do not turn the structural code into an inventory reservation mutation until confirmed.

## Implemented structural representation

`reservation_records` is a **provisional storage table** that can represent:

- `hotel_id` — owner / property scope
- `reference` — unique within that property
- `room_type_id` — selected catalogue room type (does not prove allocation or sellable capacity)
- `assigned_room_id` (nullable) — optional physical room assignment; same hotel and same room type required by a composite foreign key
- `check_in` / `check_out` — date-only hotel-local nights, half-open `[check_in,check_out)`
- `lifecycle_state` (nullable string) — intentionally not enumerated until a state machine is agreed

No money, rate plan, guest identity, hold, channel, payment, taxes or real availability status is inferred here. The table currently has no public or authenticated mutation endpoints.

## Date calculations

`StayPeriod` verifies strict date-only values and checkout after checkin. Two stays overlap when:

```
a.checkIn < b.checkOut AND a.checkOut > b.checkIn
```

Thus a checkout on the 20th and an incoming guest checking in on the 20th do **not** consume the same night. This aligns with the frontend reservation calendar's half-open date display.

`RoomOverlapDetector` only identifies overlaps among supplied **known physical-room stays**; it does not filter statuses, calculate inventory, reserve a lock, account for unassigned room-type reservations or apply maintenance blocks. An empty result never means availability.

### Schema constraints
- Positive-length stays enforced by MySQL 8.4 and PostgreSQL CHECK constraints, and SQLite insert/update triggers.
- Property-scoped reference uniqueness and reservation date indexes.
- Room type constrained within the property.
- Optional physical-room assignment constrained to **the same property AND room type**, using a composite key.
- Room type and physical-room deletes are restricted when referenced.

These database invariants intentionally do **not** prevent overlapping reservations. A concurrency-safe inventory engine will need an approved status/hold model and transaction strategy, followed by MySQL/InnoDB and PostgreSQL concurrency tests.

## Read-only endpoint

`GET /api/v1/hotels/{hotel}/reservation-records?page=1&per_page=20`

Requires active authentication and active hotel membership. Returns only reference, type, optional assigned room, stay dates and stored lifecycle label. The response meta includes `isAvailability: false`.

No reservation create/update/delete/check-in/check-out endpoint exists. This read endpoint does **not** satisfy the frontend reservation calendar or creation wizard API contracts.

## Business decisions to resolve before Phase 3

| Decision | Alternatives | Downstream implications |
| --- | --- | --- |
| Inventory sold | Specific room / room type / hybrid | Capacity locks, change room assignment, overbooking controls |
| Room assignment | At sale / before arrival / check-in | How unassigned bookings affect availability |
| Pending holds | Immediate / timed / no hold | Expiration jobs and transactional checks |
| Inventory blocks | Maintenance / staff manual blocks / both | Reason, priority, effective nights, availability |
| Overbooking | Prohibited / controlled authorized override | Conflict errors and audit trails |
| State transitions | Tentative, confirmed, checked in, checked out, cancelled, no-show | Which statuses consume inventory; restoration behavior |
| Amendments | Date changes and upgrades | Reprice vs preserve rates; atomic reallocation |
| Hotel local time | Cutoffs / early arrival / late checkout | Billing and overnight reconciliations |
| Rates / finance | Room type rates, tax, discounts, payment and deposits | Booking totals and financial correctness |
| Property scope | Single initial property / multiple active properties | Tenant selection, users and roles |

No option in this table is an approved hotel policy. Seek owner/operator confirmation before implementing writes.

## Tests
- `tests/Unit/StayPeriodTest.php`: date validity, checkout exclusivity, year boundaries
- `tests/Unit/RoomOverlapDetectorTest.php`: same-room overlap without claims of availability
- `tests/Feature/ReservationSchemaTest.php`: scoped FK, date integrity, read-only endpoint, tenant isolation

No database-level simultaneous booking tests yet, because there is intentionally no booking mutation or inventory allocator.

## Frontend continuity

The last completed frontend milestone remains **Hotel Settings & Configuration**. Its reservation calendar, physical-room timeline and mock booking wizard continue in mock mode. There is no corresponding live reservation mutation to integrate.

## Both SQL database engines

The same room-type/physical-room FK schema and date ordering constraint are intended for MySQL 8.4 LTS and PostgreSQL 16+. Database engine selection happens in `.env`. See [Database compatibility](DATABASE_COMPATIBILITY.md). This does not add an inventory availability allocator or any reservation write API.
