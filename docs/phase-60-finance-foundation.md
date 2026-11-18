# Phase 60: VAT and earned room revenue foundation

## Safety boundary

This phase is **not fully activated**. The existing booking/folio flow may
credit room revenue at pricing time. Do **not** run nightly recognition for
those journals: `NightlyRoomRevenueRecognitionService` accepts only journals
explicitly designated `folio_deferred` that credit `DEFERRED_ROOM_REV`.

Neither the tax preview nor revenue recognition changes `folio_entries`,
`folio_adjustments`, payment evidence, the guest's amount due, tax invoices,
or gateway settlement. They are separate accounting operations.

## Tax classification

- `vat_standard` requires an explicitly configured positive basis-point rate.
  The general Philippine VAT rate is commonly 12% (1200 bps); it is **not**
  automatically activated.
- `vat_zero`, `vat_exempt`, and `non_vat` remain distinct classifications,
  all calculated with a zero VAT line.
- Hotel administrators must draft and approve a policy with an effective date.
  Approval rejects overlapping approved date ranges and retrospective starts.
- The amount passed to tax preview must already reflect any applicable
  discounts. The calculator does not determine SC/PWD eligibility, apportion
  shared accommodation, or generate legally compliant tax invoices.
- Policy preview is a nonposting illustration, not a source of tax payable.

## Admin API

- `GET /api/v1/hotels/{hotel}/finance/tax-policies`
- `POST /api/v1/hotels/{hotel}/finance/tax-policies`
- `POST /api/v1/hotels/{hotel}/finance/tax-policies/{policy}/approve`
- `GET /api/v1/hotels/{hotel}/finance/tax-preview?date=YYYY-MM-DD&amountMinor=11200`

All four endpoints run under existing `auth:sanctum`, `active.user`,
`hotel.member`, and `hotel.admin` middleware.

## Accounting rules

- A valid deferred folio source journal posts credit
  `DEFERRED_ROOM_REV`. The recognition service requires its source journal
  ID, a stay date equal to the hotel's open financial business date, and an
  integer centavo amount within the remaining deferred balance.
- Earned room revenue: debit `DEFERRED_ROOM_REV`, credit `ROOM_REV`.
- Uniqueness `(hotel_id, source_journal_id, stay_date)` prevents a duplicate
  nightly recognition; replay of the same amount returns the original journal.
- A correction is an append-only reversed journal, with full provenance;
  no historical records are edited and no guest is charged.
- The per-hotel financial clock lock serializes writers with Phase 59 close.
- No scheduler, automatic booking integration, tax posting, or historical
  backfill is enabled in this slice.

## Before production activation

1. Classify the existing booking journal pathway: already-earned revenue vs.
   deferred liability; avoid retroactive double counting.
2. Reconcile tax-inclusive/exclusive rate plans, SC/PWD discount eligibility,
   BIR invoicing requirements, local levies, and non-VAT registration with
   hotel finance and a qualified Philippine tax professional.
3. Implement immutable per-reservation tax allocation snapshots and a
   reviewed tax-liability posting workflow.
4. Add a time-zone-aware nightly recognition orchestration with reconciliation
   to folio line items; prove concurrency against the production database engine.
5. Run migrations and full Laravel tests on staging, confirm transaction
   rollback, constraints and deployment health.

## Phase 60 continuation: connected, reviewed folio-night accounting

The following API is implemented under `auth:sanctum`, `active.user`,
`hotel.member` and `hotel.admin`:

- `GET /api/v1/hotels/{hotel}/bookings/{booking}/folio-night-entries`
- `GET /api/v1/hotels/{hotel}/bookings/{booking}/folio-night-assessments`
- `POST /api/v1/hotels/{hotel}/bookings/{booking}/folio-night-assessments`
- `POST /api/v1/hotels/{hotel}/bookings/{booking}/folio-night-assessments/{assessment}/approve`
- `POST /api/v1/hotels/{hotel}/bookings/{booking}/folio-night-assessments/{assessment}/recognize`
- `GET/POST /api/v1/hotels/{hotel}/bookings/{booking}/folio-night-assessments/{assessment}/corrections`
- `POST /api/v1/hotels/{hotel}/bookings/{booking}/folio-night-assessments/{assessment}/corrections/{correction}/approve`

The manager UI route is `/folio-night-accounting`.

Tax and benefit assessments are anchored to **existing** `folio_entries`
(`entry_type=room_night`). A unique hotel/folio-entry assessment prevents
repeat accounting source creation. Preparation and approval require different
administrator IDs. Approval is an atomic transaction sharing the Phase 59
hotel clock lock with journal posting and day close.

For an approved VAT-inclusive room night:

1. Source journal: debit `AR`, credit `DEFERRED_ROOM_REV` for the original
   folio amount (no additional `folio_entries` row is created).
2. If an eligible statutory benefit was independently verified, post the
   approved folio CREDIT and a balanced `DEFERRED_ROOM_REV` /`AR` journal.
   The credit includes both VAT relief and the 20% of eligible VAT-exclusive
   accommodation. Mixed-occupant bills require staff-approved eligible share.
3. VAT liability: debit `DEFERRED_ROOM_REV`, credit `VAT_PAYABLE` for
   the taxable non-exempt share, with immutable policy/folio linkage.
4. When the stay date is the current open financial business date,
   recognize the approved net deferred amount: debit `DEFERRED_ROOM_REV`,
   credit `ROOM_REV`.
5. Changes in verified eligibility use a NEW two-person correction record,
   a balanced delta journal, and a compensating folio debit/credit. The original
   assessment/journals are preserved; payments block such corrections pending
   separate refund reconciliation.

This is **not** BIR-compliant invoice issuance or proof that a tax obligation
arose on the journal date. Before enabling, finance must verify the VAT tax
point, invoice event, rate-plan semantics, hotel VAT registration,
senior/PWD eligibility documentation, mixed-guest allocation, LGU charges,
and handling of partially settled/legacy bookings.

### Production-safe activation

`FINANCE_ENABLE_LIVE_FOLIO_TAX=false` is the default in
`config/financial_accounting.php`. Keep it OFF until sign-off and a staging
backfill/reconciliation plan exist. Admin policy configuration and previews
may be used without activating live folio tax posting. Any previously posted
assessment still activates safety requirements for its own financial date
even if the toggle is subsequently disabled.

Enable only on an isolated staging environment AFTER passing the tests and
reconciling the hotel chart of accounts:

```bash
php artisan test --filter=FolioNight
php artisan test --filter=PhilippineTaxCalculator
php artisan test --filter=FinancialBusinessDay
php artisan test
```

For two-connection locking verification, use a dedicated disposable
MySQL/InnoDB or PostgreSQL **test** database named with a `test`, `ci`,
`staging`, or `sandbox` identifier, `APP_ENV=testing`,
`FINANCE_REAL_DB_LOCK_TESTS=1` and the appropriate `DB_CONNECTION`.
Then run:

```bash
php artisan test --filter=FinancialRealDatabaseConcurrency
```

The real-engine test proves actual row-lock exclusion on separate database
connections. It must be *executed on a real configured database*; SQLite
passing is NOT equivalent. Also run the full integration tests against that
engine before enabling production journal writes.

### Not yet production-certified

- No historical backfill of existing charged/earned folios is attempted.
  Existing journals may already recognize revenue; these must be reconciled
  before a new deferred source is approved.
- No BIR invoice/receipt generation, tax-event verification, external
  settlement verification, scheduled night-audit posting, or automatic
  rate-plan tax-inclusive conversion exists in this phase.
- Reviewed discounts on partially paid folios intentionally require a
  separate refund workflow.
- True concurrent API posting/approval/close workload tests and external tax
  compliance sign-off remain required.
