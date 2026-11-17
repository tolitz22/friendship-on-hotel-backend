# Room Inventory — Checkout Cleaning & Occupancy Visibility

## Implemented

The existing **Rooms / Room Inventory** screen now derives independent signals from the existing PMS:
- **Occupancy:** vacant, occupied, or due for checkout today, based on *registered* checked-in reservations and hotel-local date. This is not physical sensing or proof that the room is actually empty.
- **Cleaning:** checkout cleaning required (turnover task `todo`), cleaning in progress (`in_progress`), cleaned but awaiting inspection (`done` without a currently valid approved readiness inspection), ready (approved current inspection with no current blockers), or readiness not verified.
- **Audit:** existing checkout housekeeping task ID, assigned housekeeper, task completion timestamp and staff actor, inspection creator, reviewer and timestamps. The UI opens the linked task or readiness inspection workflow directly.

**No separate mutable `rooms.status` column is introduced.** Checkout already creates a linked `checkout_clean` task atomically in `ReservationStayLifecycleService`; housekeeping task updates have revision checks and audit events; readiness inspections require a different manager to approve. The new report is read-only and never marks a room ready solely because housekeeping clicked `done`.

## API

`GET /api/v1/hotels/{hotel}/room-inventory-status?room_ids=1,2,3`

- Authenticated member with `reservation.read` permission; **hotel manager or front desk**, not housekeeping/maintenance, because occupancy includes reservation identifiers. Housekeeping manages the actual assigned cleaning task in its own existing workspace.
- Bounded 1–100 hotel-scoped room IDs; unknown/foreign IDs and duplicate IDs are rejected. The frontend requests IDs from the current paginated room catalogue, and validates the hotel, number of returned rows and every room ID.
- Each row includes `occupancy`, `cleaning`, `turnoverTaskId`, `turnoverStatus`, `assignedHousekeeperId`, `cleanedById`, `cleanedAt`, `inspectionId`, `inspectedById`, `reviewedById`, `reviewedAt`, `advisoryReady`, and maintenance/block flags.
- Responses explicitly say `snapshotOnly: true`, `advisoryOnly: true`, and `physicalOccupancyVerified: false`. This status does not lock inventory or approve actual check-in.

## Lifecycle to verify

1. **Checked in:** occupied in PMS; when check-out date is today, **Due for checkout today** (not vacant).
2. **Actual checkout:** checkout transaction creates a linked task; the room becomes **Vacant in PMS / Checkout cleaning required**.
3. **Housekeeper starts task:** **Cleaning in progress**.
4. **Task marked done:** **Cleaned · awaiting inspection**, with completion actor/time. Still not ready.
5. **Physical inspection submitted:** still awaiting independent manager approval.
6. **Independent manager approves with no blockers:** **Ready for check-in** when vacant. Inspection actor/reviewer/timestamps are visible in Room Inventory.
7. **New maintenance block or work after approval:** readiness is no longer current; the room is no longer displayed as ready. Laravel's existing room-readiness enforcement still performs a fresh safety check at check-in when the hotel has that policy enabled.

## Boundaries and reporting caveats

- The summary counts are **only for the currently visible page of room cards**; they are not hotel-wide counts.
- The new endpoint exposes no guest name or guest financial information. Hotel-specific booking IDs are limited to reception/admin.
- A checkout cleaning task is created at **actual checkout**, not at the scheduled departure time; a guest who has not left remains due for checkout.
- Missing or stale inspection status is **not** interpreted as ready, and an unassigned housekeeping task is **not** automatically completed.
- Existing UI and API permissions stay in place, and keycard/CCTV modules remain deferred.

## Validation

```bash
php artisan test --filter=RoomInventoryCheckoutCleaningTest
php artisan test --filter=CheckoutTurnoverHandoffPhase40Test
php artisan test --filter=RoomReadinessPhase35Test
php artisan test
```

Frontend:

```bash
npm test
npm run build
```

A deployed backend must include the new endpoint before the updated Room Inventory frontend can show the operational cards.
