# Phase 53 — Independent Occupancy Verification

## Goal and scope

Compare physical-room **observations** with registered checked-in stays without allowing front-desk staff to falsify observations or housekeeping staff to rewrite the PMS booking roster. This detects some anomalies, **not definitive evidence of fraud**. Observations can be mistaken, staff can collude, and physical observation may be impossible without consent. No CCTV/keycard/door-sensor integration exists in this phase.

### Roles

- `admin`: assign a daily room verification to an **active housekeeping** hotel member; see all current/historical hotel reports; review submitted observations and mark matching records `verified`, or flag exceptions `flagged` with an explanatory note.
- `housekeeping`: see only explicitly assigned verification records; submit one observation for the hotel-local assignment date with a nonblank note. Cannot change booking status or inspect other staff's reports.
- `front_desk`, `maintenance`: no access to verification endpoints, even if they can view a room catalogue or edit their own tasks.

The reviewer cannot be the observer. Administrators cannot submit housekeeping observations. Each hotel room has a database-enforced **unique daily assignment** to prevent replacement or duplicate evidence; assignments are not reassigned after creation. Assignments, submitted observations, snapshots, manager reviews and event histories have no delete/update endpoints.

### PMS comparison

At observation submission, under the shared hotel-first transactional lock, capture registered reservations whose `assigned_room_id` is the room ID, whose `lifecycle_state` is `checked_in`, and whose check-in date is at or before today's hotel-local date. Overdue bookings still recorded as checked-in remain expected occupied. Do not use `confirmed` but not checked-in reservations to infer actual occupancy. Persist the **booking IDs present at that moment** for later review.

Possible classifications:

| Observation | PMS expected | Classification |
| --- | --- | --- |
| occupied | occupied | `match` |
| vacant | vacant | `match` |
| occupied | vacant | `unexpected_occupied` |
| vacant | occupied | `expected_occupied_vacant` |
| unknown | either | `unverified` |

Unknown/discrepant observations cannot be marked verified. They must be flagged and investigated; neither decision automatically creates bookings, changes housekeeping status, modifies revenue or changes room readiness.

### API

All routes are under `/api/v1/hotels/{hotel}`:

| Method | Path | Authorization |
| --- | --- | --- |
| GET | `/occupancy-verifications?status=submitted&page=1&per_page=20` | Admin sees hotel queue, housekeeping sees own assignments |
| GET | `/occupancy-verifications/{verification}` | Admin or assigned housekeeping only |
| GET | `/occupancy-verifications/{verification}/history` | Admin or assigned housekeeping only |
| POST | `/occupancy-verifications` | Admin with `hotel:write` |
| POST | `/occupancy-verifications/{verification}/observe` | Assigned active housekeeper with `hotel:read` |
| POST | `/occupancy-verifications/{verification}/review` | Admin with `hotel:write`, cannot review own observation |

Assignment body:

```json
{"roomId": 9, "assignedUserId": 21, "inspectionDate": "2026-11-18"}
```

Observation body:

```json
{"observedOccupancy": "occupied", "note": "Observed presence from common area during authorized inspection"}
```

Review body:

```json
{"decision": "flagged", "note": "Discrepancy requires independent room and payment investigation"}
```

The list response includes `meta.hotelDate` as the authoritative date for today's assignment. The UI must not supply its browser-local clock as the authoritative date.

### Safety and limitations

- No unconsented room entry, covert surveillance, or disclosure of guest-identifying details is required. Capture only necessary descriptive observations.
- A manager **cannot** erase or quietly change findings. Manual direct SQL writes by a privileged DBA remain possible; DB least-privilege and backups are required.
- Daily inspections are manager-assigned; missing assignments do **not** imply rooms are vacant. This is not continuous occupancy telemetry.
- Even a `match` means only two data sources agreed. It is not independently verified payment, legal guest registration, room readiness, or absence of misconduct.
- Phase 51 manager approval and Phase 52 cash controls remain separate and unchanged.

### Rollout

1. Deploy backend and run `php artisan migrate`.
2. Run `php artisan test --filter=OccupancyVerificationPhase53Test`, full `php artisan test`, `php artisan route:list` and PHP lint.
3. Run frontend `npm test`, `npm run build` and browser tests for manager/housekeeping/front desk/cross-hotel roles.
4. Test lock/race behavior for observation concurrent with check-in, checkout and room reassignment on MySQL/PostgreSQL, not only SQLite.
5. Conduct physical inspection/privacy training, variance escalation, property reporting and manager oversight before production rollout.

Tests and production race behavior have **not** been verified by this source-edit session.
