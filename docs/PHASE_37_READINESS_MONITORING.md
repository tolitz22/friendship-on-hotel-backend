# Phase 37 — Room Readiness Coverage & Upcoming Arrival Monitoring

**Source committed to backend \`main\`; tests, SQL engine runs and cross-process concurrency tests not executed in this integration.** The Phase 36 mandatory readiness gate remains **opt-in**, with its per-hotel value shown in this report. This feature adds no migrations or booking mutations.

## Implemented endpoint

\`\`\`http
GET /api/v1/hotels/{hotel}/readiness/dashboard?days=7&room_page=1&room_per_page=20
\`\`\`

The endpoint uses the existing authenticated active-hotel-member middleware. \`days\` accepts 1–14 (default 7), room pagination defaults to 20 and caps at 100. The server calculates \`asOfHotelDate\` using the hotel's configured timezone. The future arrival window is half-open: \`[asOfHotelDate, arrivalWindowEndExclusive)\`.

The response includes:
- \`data.hotelId\`, \`asOfHotelDate\`, \`arrivalWindowDays\`, \`arrivalWindowEndExclusive\`, and the **actual** \`enforceAtCheckIn\` policy.
- \`data.summary\`: hotel-wide \`totalRooms\`, \`approvedCurrent\`, \`awaitingReview\`, \`needsInspection\`, and \`blockedOrStale\`. Values must sum to \`totalRooms\` and are **not** computed from a room page.
- \`data.rooms\`: a server-paginated room list of room ID/number/type, latest inspection and its status, and six explicit readiness blocker booleans.
- \`data.arrivals\`: **all confirmed arrivals in the requested bounded date window** (not a booking total or a list of all future reservations), with reference, dates, nullable physical room and one risk: \`unassigned_room\`, \`room_record_missing\`, \`inspection_or_blocker\`, \`no_current_readiness_issue\`.
- \`meta.roomPage\`, \`roomPerPage\`, \`roomTotalPages\`, \`arrivalTotal\`, \`snapshotOnly:true\`, \`futureRoomReadinessGuaranteed:false\`, \`inventoryGuaranteed:false\`, \`guestOrPaymentVerified:false\`.

The risk \`no_current_readiness_issue\` means **only** that the currently assigned room has an approved current inspection without present-day blocker evidence; it **does not** certify that room will be vacant, available, inspected or safe on arrival day.

## Data accuracy and limits

\`RoomReadinessDashboardService\` bulk-loads latest inspection IDs, task/turnover history IDs, open room-impact work orders and today's active dated blocks for the selected hotel. It uses immutable inspection-time sequence snapshots from Phase 36 to detect subsequent room work even if the later work was completed. Room counts and arrival risks are computed on the server, not guessed in the React UI. The report is read-only, uses no locks, and is therefore a **non-atomic operational snapshot**, not a transactionally isolated guest check-in decision.

For bounded synchronous response time and to avoid presenting partial hotel-wide data, this first version rejects hotels with more than **1,000 registered rooms** or reporting windows with more than **2,000 confirmed arrivals** (HTTP 422), rather than silently truncating. Add streaming/aggregated queries and benchmarking before increasing those limits. Room pagination never limits the accuracy of the summary counts.

The dashboard assesses **current readiness today** and displays arrival date only as upcoming work context. Do not use it to claim future-date inventory availability or to bypass the live Laravel check-in gate.

## Frontend integration

In \`VITE_DATA_SOURCE=api\`, the React Rooms page mounts \`ReadinessDashboardPanel\`: hotel summary cards, an arrival exception queue with local pagination/filtering, a backend-paginated room inspection queue and explicit as-of date/rollout state. Client query keys include hotel, date-window length and room page, and strict schema validation rejects mismatched hotel identity, unreconciled totals or partial arrivals. Related booking, assignment, maintenance, housekeeping, inspection and policy writes invalidate the dashboard cache. The report can also be manually refreshed. Mock mode is unchanged.

## Verification checklist

\`\`\`bash
git pull origin main
php artisan migrate
php artisan route:list --path=api
php artisan test --filter=RoomReadinessDashboardPhase37Test
php artisan test --filter=RoomReadinessEnforcementPhase36Test
php artisan test
\`\`\`

Test in at least MySQL/InnoDB and PostgreSQL, ideally with parallel application processes. Confirm hotel isolation, arrival-window boundary, status counts after inspection approval/rejection, newer housekeeping activity after approval, active maintenance/block appearance, missing physical assignment, and no false positives on future availability.

### Lock contention and race-test runbook

The dashboard is a **read-only observational query**. Phase 36's hotel-first lock still needs concurrency validation, separate from dashboard unit tests:

1. Prepare an isolated hotel and a confirmed reservation with a physical room. Enable readiness enforcement only in test/staging.
2. Establish current independent inspection approval with no blockers.
3. Run two real worker processes against **separate** database connections, coordinating with barriers: process A attempts check-in while process B creates an active room-impact work order. Accept only serializable outcomes: either check-in commits first and maintenance follows, or maintenance commits first and check-in is rejected 409. No partially saved reservation transition is allowed.
4. Repeat for checkout turnover creation vs inspection approval, housekeeping creation/completion vs check-in, active inventory block creation vs inspection approval and policy enable/disable vs check-in.
5. Measure p50/p95/p99 transaction durations, lock wait counts/timeouts, deadlocks and throughput with 5, 25 and 100 concurrent staff workers against one hotel; compare to concurrent staff distributed across different hotels. Investigate per-hotel serialization hot spots.
6. Check database engine specifics and direct DB mutation paths. The hotel-first lock applies only to writers conforming to the protocol; direct SQL, queue jobs and new endpoints must not bypass it. Never claim cross-process race safety from PHPUnit's shared SQLite in-memory connection.

**These experiments have not been run here.** They remain release acceptance gates before enabling the hotel's readiness policy in production.
