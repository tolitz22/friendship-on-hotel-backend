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
