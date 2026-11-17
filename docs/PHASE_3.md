# Backend Phase 3 — Read-only room conflict diagnostics

This milestone deliberately **does not** implement or expose a booking engine. The hotel's sellable inventory unit, hold policy, statuses and overbooking rules remain unresolved.

## New endpoint

`GET /api/v1/hotels/{hotel}/rooms/{room}/reservation-overlaps?from=YYYY-MM-DD&to=YYYY-MM-DD`

Requires a Sanctum-authenticated active staff account, `hotel:read` ability and an active membership in the requested hotel. A room belonging to another hotel returns 404.

The reporting interval is **half open**, `[from,to)`, following the existing frontend reservation calendar and backend `StayPeriod` semantics. Maximum 31 nights per request; both dates are required.

## Response example

```json
{
  "data": {
    "roomId": "101",
    "records": [
      {"id":"1","reference":"A","checkIn":"2026-11-17","checkOut":"2026-11-20"},
      {"id":"2","reference":"C","checkIn":"2026-11-19","checkOut":"2026-11-21"}
    ],
    "overlappingPairs": [
      {"firstId":"1","secondId":"2","firstReference":"A","secondReference":"C"}
    ]
  },
  "meta": {
    "hotelId": "1",
    "from":"2026-11-17",
    "to":"2026-11-23",
    "timeZone":"Asia/Manila",
    "isAvailability":false,
    "complete":true,
    "scope":"assigned_records_only_all_states",
    "excluded":[
      "unassigned_reservations",
      "maintenance_blocks",
      "inventory_holds",
      "operational_status_policies"
    ]
  }
}
```

Both rooms and reservations are selected from the authenticated hotel context. Only records **assigned to the specific physical room** and whose dates overlap the diagnostic window are examined.

Pairs are generated from intersecting reservation-record date intervals. A shared checkout/check-in date does not overlap. **All lifecycle states are included** because the system does not have an approved inventory-consuming status policy. Thus a reported pair may not be an actionable overbooking; it is a potential structural collision requiring policy review.

Unassigned room-type reservations, maintenance blocks, room holds, walk-ins, no-shows, state transitions, physical occupancy, and real pricing are not accounted for.

**An empty result does not mean a room is available.** Never expose the endpoint as a booking eligibility checker or use it to authorize any inventory writes.

## Error and completeness behavior
- Missing, invalid, reversed or excessively long windows return 422.
- Inaccessible room/hotel records return 404.
- Unauthenticated requests return 401; tokens without hotel read permission return 403.
- More than 500 structural records in one 31-night query returns HTTP 422 and `complete:false`, rather than silently truncating the diagnostic.

## Portability
- Uses Laravel's query builder with ordinary comparisons; works in both MySQL 8.4/InnoDB and PostgreSQL 16+, as well as SQLite tests.
- No database-specific overlap functions, range types, exclusion constraints or locks.
- Query uses the existing room/date index `res_room_stay_idx`.
- The comparison algorithm is bounded by the 500-row cap; no occupancy/availability cache is introduced.

## Files
- `app/Domain/Reservations/AssignedRoomConflictAnalyzer.php`
- `app/Http/Controllers/Api/RoomConflictDiagnosticsController.php`
- `routes/api.php`
- `tests/Unit/AssignedRoomConflictAnalyzerTest.php`
- `tests/Feature/RoomConflictDiagnosticsTest.php`

## Next milestone after verification
Before building real inventory mutations:
1. Approve sellable unit: room type, physical room or hybrid.
2. Approve the inventory-consuming lifecycle states, holds/expiry, maintenance blocks and overbooking rules.
3. Design transactional capacity/room locking and idempotent booking creates.
4. Test concurrent competing bookings in BOTH MySQL/InnoDB and PostgreSQL, not just in-memory SQLite.
5. Reconcile backend property-scoped auth/DTOs with the existing React frontend.

Frontend remains in `VITE_DATA_SOURCE=mock` after the Hotel Settings milestone.
