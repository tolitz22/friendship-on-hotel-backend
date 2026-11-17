# Phase 36 — Opt-in Transactional Room Readiness Enforcement

**Source implementation on backend main; automated tests, migrations and multi-process race validation NOT executed here.** This feature changes real check-in behavior only if a hotel administrator enables the policy. **Default: OFF for every hotel**, including hotels with no policy row.

## What changes

- \`hotel_readiness_policies\` stores hotel-specific \`enforce_at_check_in\`, revision, actor, reason and timestamps. \`hotel_readiness_policy_events\` records each enable/disable change in an append-only audit.
- The \`ReadinessWriteGuard::lockHotel\` protocol acquires a **stable hotel row FOR UPDATE** before subordinate room type, room, reservation, housekeeping, maintenance and inspection locks for readiness-sensitive writes. This deliberately serializes the affected writes **per hotel**. It is coarse but avoids relying on locking an absent inspection/block row. InnoDB and PostgreSQL require an actual transaction around the lock.
- The following writers have been migrated to the hotel-first protocol: inspection submit/review, readiness policy updates, reservation check-in/checkout, assignment, inventory block creation/release, housekeeping creation/update, maintenance work-order creation/update, room creation/edit, room-type edit, and date amendments. Existing booking creation still uses its room-type lock, which check-in also acquires after the hotel lock.
- Under opt-in enforcement, \`ReservationStayLifecycleService::transition\` calls \`ReadinessWriteGuard::requireReady\` **inside the check-in transaction** and **after locking the hotel and reservation room type**. It denies check-in (HTTP 409) unless the selected physical room's most recent inspection is independently approved and no active readiness blockers exist. The preexisting stay dates, assigned room, inventory block and other assignment-collision checks remain in force.
- Failed check-in leaves the reservation in \`confirmed\`; no housekeeping, reservation or financial changes are committed.
- The readiness response now returns the actual \`enforcedAtCheckIn\` policy state; \`inventoryGuaranteed\` remains \`false\`. Enforced inspection is **not** a guest identity, payment or fiscal settlement check.

## Operational invalidation

Inspection now snapshots immutable, monotonically increasing room-specific sequence IDs for:
- Latest linked checkout turnover
- Latest housekeeping task (all types)
- Latest room-impact maintenance work order
- Latest dated inventory block

A subsequent room task, relevant work order, block or turnover **invalidates the older inspection** even if it is later completed, closed or released. A current unfinished housekeeping task, incomplete checkout turnover, current-day active block, or active impacting maintenance order also blocks approval. The snapshots avoid same-second timestamp races between inspection and checkout.

Old inspections before the snapshot migrations default to ID zero. If they have historical room activity, they conservatively become unready until inspected again.

Note: readiness is a **current operational check**, not a future-night physical inventory hold; separate booking availability, arrival dates, conflict and safety workflows still apply.

## APIs

All paths below are prefixed \`/api/v1/hotels/{hotel}\`, require active hotel membership for reads and hotel administrator permission for PATCH:

\`\`\`http
GET   /readiness/enforcement-policy
GET   /readiness/enforcement-policy/history?page=1
PATCH /readiness/enforcement-policy
GET   /rooms/{room}/readiness
\`\`\`

Example toggle after reading current revision:

\`\`\`json
{
  "expectedRevision": 0,
  "enforceAtCheckIn": true,
  "reason": "Physical inspections completed and trained front desk staff approved the rollout"
}
\`\`\`

A missing policy row has revision \`0\` and is disabled. The first update creates revision \`1\`. Each later change requires the **exact current revision**. Stale revisions and duplicate same-state requests return 409. Every accepted change is audited. A hotel member without administrator rights cannot update it.

The backend continues to accept the Phase 35 independent inspection and review endpoints. A person cannot approve their own inspection. Check-in reads the current assessment under the hotel lock; the React front desk performs only a helpful, never authoritative precheck.

## Safety, performance and rollout

1. **Do not enable during an unverified deployment.** First apply all three new migrations: policy/events (\`360000\`), turnover sequence (\`361000\`) and operational sequence snapshots (\`362000\`) after existing Phase 35 migrations.
2. Run the complete PHP suite and isolated hotel check-in/maintenance/housekeeping test scenarios; verify actual fresh migrations on SQLite, MySQL/InnoDB and PostgreSQL.
3. Test truly concurrent processes: approved inspection vs new room-impact maintenance, check-in vs task creation, approval vs inventory block, checkout turnover vs inspection, and policy toggle vs check-in. Confirm a failed check-in never commits. Use database connections from **separate processes**, not a single SQLite connection. Verify locks don't introduce deadlocks or unacceptable per-hotel contention.
4. Inspect every room needed for upcoming arrivals; ensure housekeeping and maintenance queues are resolved, independent administrators can approve inspections, and staff understand the new 409 responses. Document the hotel's operational policy.
5. Enable through the admin Rooms panel with an explicit reason and current revision; monitor check-in conflicts, throughput and transaction waits.
6. To roll back behavior safely, set \`enforceAtCheckIn:false\` using the current revision and a reason, then verify it is recorded in policy history. **Do not drop inspection or event tables or revert migrations to disable enforcement.**

The hotel-row lock deliberately serializes writes across the property; capacity/performance benchmarking is required before large-scale adoption. External direct SQL writers or future endpoints not using the shared lock protocol can break the serializability guarantee and must be prohibited or updated.

## Verification commands

\`\`\`bash
git pull origin main
php artisan migrate
php artisan test --filter=RoomReadinessEnforcementPhase36Test
php artisan test --filter=RoomReadinessPhase35Test
php artisan test
php artisan route:list --path=api
\`\`\`

On the frontend:

\`\`\`bash
git pull origin main
npm ci
npm run typecheck
npm test
npm run build
\`\`\`

The source was committed through GitHub, but **the project build, test suite, concurrent race checks and browser acceptance remain unverified**. Do not claim production-safe rollout until those are completed.

## Next milestone

A hotel readiness rollout dashboard with batch room inspection coverage, conflicts/blocked-arrival forecasting and multi-user operational race tests, followed by a finer-grained locking performance design if hotel-level contention becomes significant.
