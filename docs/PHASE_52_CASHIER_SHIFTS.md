# Phase 52 — Cashier Shifts and Cash Reconciliation

## Scope

This phase tracks **recorded physical cash custody** for active front-desk and administrator users. It adds an auditable shift lifecycle and makes new cash payments possible only through a shift-linked folio cash receipt.

- `open`: cashier declares their opening float in minor currency units.
- `cash_received`: payment is written by the existing folio accounting service as `pending_review` and linked to exactly one shift and reservation in the **same database transaction**.
- `submitted`: cashier independently declares their counted cash. Backend computes and stores opening float + all recorded cash receipts, and the signed variance.
- `reconciled`: a *different hotel administrator* independently accepts only a zero-variance shift with **all cash payment evidence staff-confirmed**.
- `flagged`: a different manager marks the submitted drawer for investigation and records a mandatory explanation. It cannot be reopened or silently revised.

Amounts are unsigned integer **minor units**, such as PHP centavos. No float arithmetic is used in the backend. The clerk is never sent expected cash before their count is submitted. Shift records and their payment links are immutable after submission, and each recorded payment can link to only one shift by database unique constraint. A cashier may have only one `open` or `submitted` shift per hotel (serialized under the hotel row lock).

This is **not** a completed physical safe drop, bank deposit, or verified room-occupancy report. Rejected or pending payment records still represent cash that was originally logged as received; they do not automatically reduce custody. This phase intentionally does not support cash refunds/payouts, petty cash, carry-over float transfers, or cash drawer write-offs; those require their own manager-reviewed movement records.

## API

All endpoints are relative to `/api/v1/hotels/{hotel}`, and use active hotel membership and Sanctum authentication:

| Verb | Endpoint | Permission |
| --- | --- | --- |
| GET | `/cashier-shifts?status=open&page=1` | Receptionist sees own; manager sees all hotel shifts |
| GET | `/cashier-shifts/{shift}` | Owner or manager |
| GET | `/cashier-shifts/{shift}/history` | Owner or manager |
| POST | `/cashier-shifts` | Active front desk (`booking:write`) or admin (`hotel:write`) |
| POST | `/cashier-shifts/{shift}/bookings/{booking}/cash-receipts` | Owning cashier of open shift |
| POST | `/cashier-shifts/{shift}/close` | Owning cashier of open shift |
| POST | `/cashier-shifts/{shift}/reconcile` | Different hotel administrator, with `hotel:write` |

Examples:

```json
{"openingFloatMinor": 50000}
```

```json
{"requestKey": "unique-client-idempotency-key", "reference": "CASH-RECEIPT-2026-00001", "amountMinor": 250000}
```

```json
{"countedCashMinor": 300000, "note": "Drawer counted by cashier"}
```

```json
{"decision": "reconcile", "note": "Physical count and receipt records independently checked."}
```

For a shortage/overage or disputed receipt the manager must use `decision: "flag"` and a meaningful note. No self-reconciliation.

## Legacy cash entry safety

`POST /bookings/{booking}/payment-records` **now rejects method=`cash` with 409**. New cash must be created under the shift's `cash-receipts` endpoint; bank-transfer payments continue the previous direct manager-only workflow. Payment references remain unique hotel-wide per method and the underlying FolioAccountingService still creates `pending_review` records. Existing historical unlinked cash records are not fabricated into shifts or automatically reconciled. Their idempotency keys cannot be reused to silently attach them to a new shift.

The cashier receipt's booking, folio, payment, amount, currency, owning cashier, shift, hotel, request key and audit event are all bound in one transaction. The finance review service now takes the hotel row lock before the folio lock, serializing payment-review state with shift close/reconcile.

## Data

- `cashier_shifts`: active drawer/float, count, expected, variance, responsible staff, reviewing manager, timestamps.
- `cashier_shift_receipts`: append-only link to existing `folio_payment_records`, unique payment ID.
- `cashier_shift_events`: actor-and-timestamp audit for opening, cash receipt, count submission, reconciliation, and flagging.

The application intentionally provides **no delete/edit routes** for these three tables. Database superusers or unmanaged SQL can still modify records; production protection requires least-privilege DB credentials, backups, and database audit controls.

## Production rollout gate

1. Back up a staging database; run `php artisan migrate` and `php artisan route:list`.
2. Run `php artisan test --filter=CashierShiftPhase52Test`, full `php artisan test` and PHP lint. Historic test suites that post cash via the old direct endpoint must be updated to create shifts.
3. Verify **MySQL/PostgreSQL concurrency**: two simultaneous shift openings for one cashier, two identical receipt retries, attempts to close while a receipt is being inserted, two manager reconciliations, and payment review concurrent with manager reconciliation.
4. Run frontend `npm test` and `npm run build`; test real cashier and manager roles, hotel switching, actual folios, stale request retries and mobile layout.
5. Reconcile any historical off-shift cash evidence as a **separate manager audit**, not an automated cash-shift import.
6. Review cash payout, physical handover, drawer identity, float carryover and shift cutoff procedures before allowing real hotel cashier operations.

Phase 51 manager exception approvals and prior reservation/finance safeguards remain in their original endpoints. Phase 52 was source-authored on `main`, but end-to-end production behavior and concurrency results are **not verified** in this session.
