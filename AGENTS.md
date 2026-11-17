# Friendship On Hotel — Backend Development Instructions

Independent repository: `tolitz22/friendship-on-hotel-backend`.
Frontend: `tolitz22/friendship-on-hotel-frontend`.

Read `docs/BUSINESS_RULES.md`, `docs/ARCHITECTURE.md`, and `docs/FRONTEND_HANDOFF.md` before implementing features.

## Phase 1 baseline
- Laravel 12, PHP >=8.3, PostgreSQL in development, SQLite in tests.
- Sanctum Bearer token API auth, active user and active hotel membership checks.
- Read hotel, room types, physical rooms; admin-only update contact fields and create room type/room.
- Hotel-specific access always enforced on the server. Never trust client-provided hotel IDs alone.
- Explicit DTOs; keep read-only inventory conditions distinct from availability.
- CLI-only bootstrap admin. Do not add default staff credentials or public registration.

## Non-goals
- Do not invent booking unit, cancellation, check-in, billing, room holds or tax policies.
- Do not implement reservation creation/cancellation, inventory allocations, real rates, payments, or folios without policy approval.
- Do not automatically infer occupancy, maintenance or housekeeping readiness from physical-room master data.
- Do not modify the frontend source in this milestone. Its mock adapters remain authoritative for UI development until API contracts are reconciled.

## Quality and security
- Validate, authorize and scope writes; add regression tests for cross-hotel requests.
- Use transactions for multi-write domain operations, and test conflicting reservations once booking rules exist.
- Run `composer install`, `php artisan migrate`, `php artisan test`, PHP lint and GitHub CI.
- Never commit production secrets or real guest records.
- Don't claim a full build/test pass unless verified in a PHP/Composer environment.

## Reservation Phase 2

Review `docs/RESERVATION_DOMAIN.md` and `docs/PHASE_2.md`. `StayPeriod` uses half-open nights. `RoomOverlapDetector` only identifies conflicts among given physical-room records; it never determines availability or enforces inventory. Reservation records have **no write routes** and an intentionally unsettled state machine. Do not introduce booking mutations, inventory locks or cancellation rules until the hotel has approved the inventory unit and lifecycle.

## Cross-engine database compatibility

MySQL 8.4 and PostgreSQL 16+ are both supported targets. Read `docs/DATABASE_COMPATIBILITY.md`. Do not add a PostgreSQL-only migration or inventory SQL without adding a MySQL-equivalent implementation and tests. Protect reservation room/type composite constraints, valid positive date ranges, and tenant scoping across both databases. Use SQLite only as fast test support, not a substitute for production-engine verification.

## Backend Phase 3

Read `docs/PHASE_3.md`. The room-conflict diagnostic is scoped to one physical room and supplied date window. It is read-only and explicitly not an availability function. Do not let the absence of collision pairs trigger a booking, room release, price quote, or check-in eligibility decision. Preserve both MySQL and PostgreSQL regression coverage.

## Guest Management Phase 4

Read `docs/PHASE_4_GUESTS.md` before expanding guest profiles. Guests are property-scoped personal data, not globally shared contacts. Current authorization: active hotel members can read; active hotel admins with `hotel:write` can create/update. No guest delete, merging or reservation-link workflow without explicit policy, audit and retention requirements. Keep both MySQL and PostgreSQL tests and protect personal information.

## Phase 5 authentication and browser integration

Preserve property-bound Sanctum authorization. Frontend Phase 5 uses a temporary in-memory bearer token only; production SPA auth design is still pending. The real Guests API must never silently use mock data on network/authorization failures. Do not grant write permissions based on frontend role labels. See `docs/PHASE_5_FRONTEND_INTEGRATION.md`.

## Phase 8 catalogue editing

Read `docs/PHASE_8_CATALOG_EDITS.md`. Room/type edit PATCH calls must use the selected hotel's relationships, admin role and write token, transactionally audit actual changes, and block structural changes touching referenced reservation inventory. Never add a room type reassignment or max-occupancy override without explicit booking policy approval. Keep MySQL and PostgreSQL parity. No delete endpoint.


## Phase 20 — Initial live reservation writes

Read `docs/PHASE_20_LIVE_RESERVATIONS.md` before changing bookings. The Phase 1–19 prohibition on booking writes was superseded **only for the narrow, admin-only Phase 20 endpoints** `POST /bookings` and `POST /bookings/{booking}/cancel`. The initial policy is room-type selling, optional deferred assignment, confirmed-at-creation, no overbooking, no pricing or holds; all mutations must use `ReservationBookingService` and stable room-type locks. An unknown/nullable legacy lifecycle consumes capacity until reconciled. Do not enable front desk/public booking, availability claims, automatic room assignment, maintenance blocks, payment collection or frontend live-booking mode without implementing their full policies and tests.

Production use is gated on a green Laravel suite and parallel last-room tests on **both MySQL/InnoDB and PostgreSQL**, plus maintenance/capacity review. SQLite tests alone do not verify inventory concurrency. Client-specific policy changes require versioning and relevant code/schema changes, not silent config toggles.


## Phase 21 — Live room-type inventory and explicit dated blocks

Read `docs/PHASE_21_ROOM_INVENTORY.md` before editing inventory behavior. `RoomInventoryService` owns nightly availability, dated physical-room inventory blocks, and confirmed reservation room assignment. Booking creation rechecks availability while holding the same room-type lock. Do not treat work-order `affects_room` or housekeeping completion as automatic inventory status; those records currently have no approved effective-night rules. Admin only for blocks, release, and room assignment. Production concurrency and catalogue shrink safeguards remain unverified.


## Phase 22 — Reservation operations and catalogue locking

Read `docs/PHASE_22_RESERVATION_OPERATIONS.md`. New admin-only check-in/check-out transitions are audited and constrained by planned dates in hotel-local time; check-in requires a physical room assignment. Admin inventory-block listing supports reviewing active and released records. Catalog room creation and physical room type edits must serialize with reservation inventory locks. Do not infer housekeeping readiness, billing settlement or identity verification from a reservation lifecycle state. Parallel MySQL/PostgreSQL booking-and-block concurrency verification remains a production gate.


## Phase 23 — Reservation date amendments and turnover

Read `docs/PHASE_23_RESERVATION_AMENDMENTS.md`. Date amendments are admin-only, confirmed and pre-arrival, share room-type locks, check all amended nights, preserve physical room assignment and write immutable date audit records. Front-desk manifests are read-only and hotel-scoped. Successful checkout creates exactly one `checkout_clean` housekeeping task in the checkout transaction. No automatic room readiness, rate repricing or early-departure financial adjustment is implied. The frontend booking wizard remains disconnected pending API contract and concurrency validation.


## Phase 24 — Room rate snapshots and unpriced legacy compatibility

Read `docs/PHASE_24_FINANCE_FOUNDATION.md`. Hotel administrators may create fixed nightly `room_rate_plans`; optionally priced bookings snapshot one room-night folio entry per night atomically within booking creation. Amounts are integer minor units and quote only room subtotal; never imply tax, invoice, payment, fee or deposit calculation. Unpriced legacy booking creation still works. Priced bookings cannot be amended or cancelled with the old unfunded/no-reversal routes. Before live financial use, implement approved refund/reversal, tax and payment settlement policies plus MySQL/PostgreSQL integration tests.


## Phase 25 — Provisional Folio Ledger and Payment Evidence

Read `docs/PHASE_25_PROVISIONAL_FINANCE_LEDGER.md`. Folio adjustments and manual payment evidence are hotel-scoped, admin-only writes with idempotency keys and actor/reviewer audit. Bank-transfer evidence requires an independent staff confirmer; confirmation remains **staff attestation, not bank verification**. Ledger differences are not tax-complete payable balances, official receipts or settlement. Priced cancellations via `/cancel-priced-unpaid` require no payment evidence and no manual adjustments, then atomically credit all room-night charges before cancelling. Never assume this issues a refund. Preserve room-type/folio transaction locks and the guard on priced date amendments. Production money operations require external verification and finance-policy approval.


## Phase 26 — Draft fiscal policy and refund review

Read `docs/PHASE_26_FINANCE_POLICY_AND_REFUNDS.md`. Tax and service-charge rate drafts are versioned and **non-effective**, with strictly hypothetical previews; never apply them automatically to bookings or claim statutory correctness. Refund requests require staff-confirmed payment evidence, caps per payment record, unique request keys and a different administrator's approval. `approved` does **not** mean disbursed; no gateway, signed webhook, refund processor or bank verification is implemented. Frontend Billing API mode now offers a separate, read-only selected-hotel ledger lookup, while its main table remains demo data. Never label provisional difference as actual final balance, settled payment or fiscal invoice. No live financial launch before PHP tests and dual-engine concurrency tests.


## Phase 27 — Signed finance event inbox and fiscal review gates

Read `docs/PHASE_27_SIGNED_FINANCE_EVENTS.md`. A disabled-by-default **custom generic HMAC** event inbox stores exact authenticated transport observations with a strict allowlist, signed `hotelId` property binding and idempotent event IDs. A separate migration adds nullable `hotel_id` to support already-present unbound events, but new events require it; unbound events cannot be matched. Admin-only `GET /finance/events` is property-scoped. It is not a real bank/provider SDK and does **not** post payment settlement, refund disbursement, or ledger changes. Admin finance-event matching only checks reference/amount/currency and remains `bankOrGatewayVerified:false` and `isSettled:false`. A second administrator can mark a draft charge policy reviewed, but it remains legally unvalidated and ineffective for bookings. Never enable production payment collection or tax activation based solely on this Phase 27 adapter. Verify SQLite tests plus MySQL/PostgreSQL concurrency and use provider-specific signatures/merchant binding when a provider is selected.


## Phase 28 — PayMongo test-mode webhook boundary

Read `docs/PHASE_28_PAYMONGO_SANDBOX.md`. The PayMongo sandbox adapter is separate from the generic Phase 27 HMAC example, disabled by default, and validates the documented `t`/`te` signature over the raw request body. Require a server-configured sandbox merchant-to-hotel binding, reject `livemode:true`, accept only short allowlisted event metadata, and never automatically post to folios, refunds or reservation states. No outbound payment checkout is enabled: the current PMS cannot issue a tax-complete final total. Do not present received test events as settled money or activate live credentials without provider verification and finance-policy completion.


## Phase 32 frontend operational integration support

`ReservationHistoryController` and the read-only `GET /hotels/{hotel}/bookings/{booking}/history` route expose a paginated, hotel-scoped reservation event log (actor ID, event type, old/new state and time) for the React Phase 32 operational panel. Read is allowed to active hotel members; assignment and lifecycle changes remain admin-only. Do not claim this history is a full financial ledger or verification of room readiness or guest identity. Frontend staff UI acknowledgement is not persisted in backend. Keep transaction lock ordering and current status/dated block validation in room assignment and check-in/out.


## Phase 35 — Audited room inspection and advisory readiness

Read \`docs/PHASE_35_ROOM_READINESS.md\`. The \`room_readiness_inspections\` and append-only \`room_readiness_events\` tables record a physical cleanliness/safety/amenities checklist, an inspector, an independent administrator reviewer, status, revision and notes. The hotel-scoped read endpoint includes active blockers and explicitly sets \`enforcedAtCheckIn:false\` and \`inventoryGuaranteed:false\`. Only hotel admins may submit/review; an inspector cannot approve their own inspection. Approvals cannot succeed with a failed check, an active current-day dated inventory block, open room-impact maintenance, incomplete turnover or turnover created since the inspection. These checks are conservative but **not a transactional check-in gate**. Work-order, housekeeping and block mutation flows have not yet been coordinated under one readiness lock: do not conflate advisory readiness with safe room eligibility, and do not enable enforced check-in without a separate lock policy and concurrent integration tests.


## Phase 36 — Hotel opt-in transactional readiness enforcement

Read \`docs/PHASE_36_TRANSACTIONAL_READINESS_ENFORCEMENT.md\`. A hotel administrator can enable a versioned check-in gate with a written reason and audited policy event; default is **OFF**. Under enforcement, \`ReservationStayLifecycleService::transition\` checks the latest independently approved room inspection and current operational blockers **inside** the check-in transaction before changing the booking state. The shared \`ReadinessWriteGuard\` obtains the hotel row lock **first** for inspection, housekeeping, maintenance, room-block/assignment, room catalog, date amendment, checkout and check-in writes. All future readiness-sensitive writers MUST follow this lock protocol; external direct SQL writes are unsafe. Inspections snapshot turnover, housekeeping, room-impact work and inventory-block IDs, and new work invalidates the prior approval even if later completed. The lock is deliberately coarse per hotel and requires MySQL/PostgreSQL multi-process concurrency tests and performance benchmarks. Never turn on a production hotel's policy before testing and staff rollout. Setting the policy false with the latest revision safely disables gate behavior while preserving audit data. This does NOT verify financial settlement or guest identity.


## Phase 37 — Readiness dashboard and operational reporting

See \`docs/PHASE_37_READINESS_MONITORING.md\`. \`RoomReadinessDashboardService\` and member-readable \`GET /hotels/{hotel}/readiness/dashboard\` aggregate hotel-wide current room inspection coverage, six current blockers and confirmed upcoming arrivals over a bounded 1–14-day window. Room details are server paginated, but summary totals are calculated for **all** registered rooms, and all eligible upcoming arrivals are considered. Reject hotels exceeding the synchronous 1,000-room/2,000-arrival limits instead of reporting partial totals. Only current readiness is assessed, not future-night inventory or guaranteed room allocation. Report is a non-atomic snapshot; the Phase 36 check-in transaction remains the authoritative opt-in gate. There are no dashboard write actions. Multi-process MySQL/PostgreSQL race tests and hotel-lock contention benchmarks are documented but have not been performed; do not present them as passing CI.


## Phase 38 — Automated backend checks

The repository now has \`.github/workflows/phase38-backend-checks.yml\`: PHP 8.4 Composer install, Laravel route checks and feature tests under SQLite, plus an independent MySQL 8.4 migration job. Its existence is not proof that tests or migrations have passed; review real GitHub Actions job results and logs. This is not a true multi-process transaction race test. Do not enable Phase 36 readiness enforcement in a production hotel until the concurrency and lock-contention runbook in \`docs/PHASE_37_READINESS_MONITORING.md\` is carried out on target database engines.

## Phase 39 — Direct hotel-scoped reservation handoff

The GET /api/v1/hotels/{hotel}/reservation-records/{booking} route is member-readable and uses the hotel->reservationRecords() relation before whereKey. It powers exact booking deep links from Front Desk and newly created reservations. Return only the existing structural LiveRecord fields and never bypass hotel.member middleware or expose other hotels' bookings. The frontend uses a validated numeric booking query parameter, while all booking creation, room assignment and lifecycle mutations retain Laravel's existing admin permissions. See frontend docs/PHASE_39_CONNECTED_STAFF_JOURNEY.md for browser acceptance. New feature test: DirectReservationWorkflowPhase39Test. Source is committed but tests not yet verified.

## Phase 40 — Exact turnover task handoff

See frontend docs/PHASE_40_EXACT_RECORD_RELIABILITY.md. GET /bookings/{booking}/turnover is a hotel-member-scoped, read-only lookup of the actual linked checkout-clean task. Keep the booking lookup and linked task joined under the same hotel ID. Do not create a task on GET, claim completed housekeeping verifies readiness, or return cross-tenant task details. Test CheckoutTurnoverHandoffPhase40Test; CI status not verified.

## Phase 41 — PHP syntax CI gate

The backend GitHub Actions workflow now runs php -l on all PHP files under app, routes, database and tests before route listing and the feature suite. This is a source syntax gate, not confirmation of passing Laravel feature tests, MySQL migration checks or true multi-process room-readiness locking tests. Preserve hotel-first locking and do not enable enforced check-in in a production property without those checks.


## Phase 51 — Audited exception approvals

Read `docs/PHASE_51_MANAGER_APPROVAL_WORKFLOWS.md`. Receptionists and administrators may request only `cancel_booking` or `amend_dates` for unpriced confirmed reservations; a different active hotel admin must approve/reject. An approval executes the existing cancellation/date-amendment domain service and both approval/event writes in one transaction, under the hotel-first readiness lock and original inventory/finance checks. A stale, expired, repriced, or already-reviewed request must never bypass domain validation. The manager's actor ID, requester's ID, and original booking snapshot remain separate and auditable. Housekeeping/maintenance have no access; frontend role display is not authorization. This does not permit repricing, refunds, payment changes, keycard issuance, or overriding readiness enforcement. MySQL/PostgreSQL concurrency and the full PHP/React suites remain launch gates until verified.


## Phase 52 — Cashier shift custody and manager reconciliation

Read `docs/PHASE_52_CASHIER_SHIFTS.md`. ALL new cash payment records through HTTP must be made via the `/cashier-shifts/{shift}/bookings/{booking}/cash-receipts` endpoint, which creates an existing `pending_review` folio payment and a unique shift receipt in the same transaction. The old manager-only `/bookings/{booking}/payment-records` route rejects `method=cash`; its bank-transfer behavior remains. Cashiers can submit a count but cannot see their expected total until after count submission, or reconcile their own drawer. A different manager may reconcile only with zero variance and independently staff-confirmed receipt evidence, otherwise flag and audit the investigation. Never delete or silently adjust receipts/counts. Use the hotel-first readiness lock before payment review/reconciliation to preserve concurrency safety. No payouts/refunds, off-system cash intake, or physical safe transfer are implemented. Production deployment requires tests, migration, independent cash procedure, and MySQL/PostgreSQL concurrency verification.
