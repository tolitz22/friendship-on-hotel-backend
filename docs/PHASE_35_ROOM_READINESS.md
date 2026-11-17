# Phase 35 — Audited Room Inspection and Advisory Readiness

**Status:** Source committed on backend \`main\`. Migration and tests are written but have not been executed. **This is an independently reviewed operational readiness record, not a final check-in authorization or inventory allocation.**

## Data model

\`room_readiness_inspections\`: hotel-scoped physical room, recorded checklist (cleanliness, safety, amenities), inspector note, inspector user, review status (\`submitted\`, \`approved\`, \`rejected\`), reviewer, review note, monotonically incremented revision and timestamps.

\`room_readiness_events\`: hotel-scoped append-only event rows with inspection, room, actor ID, action and JSON details.

No migration rewrites historical reservations, housekeeper tasks, room inventory or prior maintenance records.

## APIs

All routes below have prefix \`/api/v1/hotels/{hotel}\` and require active staff membership:

\`\`\`http
GET /rooms/{room}/readiness
GET /rooms/{room}/readiness/history?page=1
POST /rooms/{room}/readiness/inspections
POST /rooms/{room}/readiness/inspections/{inspection}/review
\`\`\`

The two POST routes additionally require hotel administrator authorization and the backend \`hotel:write\` scope. Reading does not expose other hotels' rooms or inspections.

Inspection example:

\`\`\`json
{"cleaningVerified":true,"safetyVerified":true,"amenitiesVerified":true,"note":"Physically inspected and checked each item"}
\`\`\`

An administrator can submit an inspection only if the previous one is not still awaiting review. The request is not equivalent to cleaning-task completion; each box is an actual staff assertion.

Review:

\`\`\`json
{"expectedRevision":1,"decision":"approve","note":"Independent review, room checks accepted"}
\`\`\`

An administrator cannot approve their own inspection. The server rejects stale revision, already-reviewed inspection, failed checklist items, active dated inventory blocks **today**, active maintenance work orders with \`affects_room=true\`, incomplete latest linked checkout turnover, and a linked turnover created since that inspection. Rejection requires a review note but can proceed with failed checks or blockers. Submission and review both write actor-attributed audit events.

The read response includes \`inspection\`, current named \`blockers\`, \`advisoryReady\`, \`enforcedAtCheckIn:false\` and \`inventoryGuaranteed:false\`. Approval is not a room hold, official room certification, or a general occupancy/clean status. A later checkout turnover invalidates prior readiness even if its cleaning task is subsequently completed.

## Important concurrency and launch boundary

**Enforcement of the readiness assessment in \`ReservationStayLifecycleService\` is deliberately not enabled.** Existing Laravel check-in checks remain: assigned physical room, hotel-local valid arrival window, no active inventory block, and no reservation collision. Do not display Phase 35 approval as "check-in automatically allowed."

The Phase 35 inspection and review transactions lock the physical room row, but housekeeping, maintenance and inventory mutation flows do not all share a unified readiness lock and invalidation mechanism. For that reason the readiness read is **advisory and can change after it is viewed**. Fully enforced check-in gating requires a coordinated transaction/lock strategy, all operational invalidation triggers, multi-process concurrency tests, and a formal property-level policy. Do not enable enforcement by merely setting a frontend flag.

## Validation

\`\`\`bash
git pull origin main
php artisan migrate
php artisan test --filter=RoomReadinessPhase35Test
php artisan test
php artisan route:list --path=api
\`\`\`

Run on an isolated test DB and then test migrations, locking and concurrent review / checkout / block changes against MySQL and PostgreSQL. Test multiple hotels, inspector self-approval rejection, stale revisions, active maintenance or inventory conflicts, and new linked turnover after approval. No successful execution of these tests is claimed.

## Frontend integration

In API mode, the real Rooms catalogue opens \`RoomReadinessPanel\`. Selected-hotel members can view readiness and recorded audit history; administrators can submit inspections and review someone else's inspection. The UI labels readiness **advisory** and check-in **not enforced**. Mock mode stays unchanged.

**Follow-up milestone:** architect and test an explicitly enabled, transactional check-in readiness gate with consistent locking across housekeeping, work orders, inventory blocks and reservations; add expiry/refresh policy and inspect newly occupied/turned-over rooms.
