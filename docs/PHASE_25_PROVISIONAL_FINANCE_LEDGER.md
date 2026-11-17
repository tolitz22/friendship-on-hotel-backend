# Phase 25 — Provisional Folio Ledger, Payment Evidence and Charge Reversals

**Committed to backend `main`.** Laravel/PHP tests and production-engine concurrency have NOT yet been executed or verified. **Not a payment processor, compliant receipt engine, or live financial settlement system.**

## Conservative defaults and financial boundaries

- All amounts are **integer minor currency units**, inherited from the immutable reservation folio; they are not floats or cash amounts to be formatted without the ISO currency exponent.
- Initial room-night charges in `folio_entries` remain immutable. Staff create separate immutable `folio_adjustments`: `debit` (add a manual charge) or `credit` (reduce recorded charges). Every adjustment requires a reason and a hotel-scoped idempotency key.
- Payments are **evidence records**, not processor transactions. An administrator can submit a cash/bank-transfer reference and amount; the initial status is `pending_review`. A deliberate review with a note changes status to `staff_confirmed` or `rejected` once. **A different hotel administrator must confirm bank-transfer records**; the recorder may reject their own evidence.
- These records do **not** prove bank settlement, transfer finality, or authenticity. A staff-confirmed entry is not a receipt, an invoice, or a gateway webhook result. No gateway secrets or customer card information are stored.
- `folio-ledger` summarizes room subtotal, manual debits/credits, pending evidence, staff-confirmed amounts and a **provisional difference**, not an official balance or payable amount. `isSettled` and `bankOrGatewayVerified` remain `false`; taxes and fees remain unconfigured.
- Any old `GET /bookings/{booking}/folio` response continues to show the original room subtotal; use `/folio-ledger` for reconciled provisional amounts.
- A priced `confirmed` reservation may be cancelled via **`cancel-priced-unpaid` only before hotel-local arrival** if no payment record of any status and no manual adjustment exists. This writes a full room-charge `credit` and a reservation cancellation/audit in **one transaction**, using the stable room-type lock and folio lock. This **does not issue a cash or bank refund**. The existing `/cancel` still rejects priced bookings.
- Once payment evidence or manual adjustments exist, priced cancellations and date amendments remain blocked pending explicit refund, rate-repricing and tax policies. This is intentional: changing reservation state must not silently erase financial liabilities.

## APIs

Within `/api/v1/hotels/{hotel}`:

```http
GET  /bookings/{booking}/folio-ledger
POST /bookings/{booking}/folio-adjustments
POST /bookings/{booking}/payment-records
POST /bookings/{booking}/payment-records/{payment}/review
POST /bookings/{booking}/cancel-priced-unpaid
```

Reads require active property membership + `hotel:read`. All writes require an active property **administrator** and `hotel:write`.

Adjustment example:

```json
{"requestKey":"manual-debit-001","kind":"debit","amountMinor":10000,"reason":"Documented room incidentals"}
```

Payment evidence example:

```json
{"requestKey":"transfer-001","method":"bank_transfer","reference":"Bank-reference-1234","amountMinor":150000}
```

Review example:

```json
{"decision":"confirm","note":"Reviewed supporting bank statement reference"}
```

`cash` and `bank_transfer` are the only initial evidence methods. Duplicate hotel-scoped payment references or request keys with changed details return a conflict. This is intentionally staff-directed, not gateway-integrated.

## Database and safeguards

- `folio_adjustments`: append-only debit/credit entries with actor, reason, idempotency fingerprint.
- `folio_payment_records`: immutable financial claim plus one-time status review, reviewer ID and note. Payment references are unique by hotel+method.
- Folio locking serializes ledger edits; the initial booking's room-type lock serializes inventory cancellation with booking, room assignment, and inventory blocks.
- Access to folios and payment records is scoped to the hotel and its related reservation; unknown/unpriced bookings cannot be given made-up charges or payment records.

## Validation

```bash
git pull origin main
php artisan migrate
php artisan test --filter=FolioAccountingPhase25Test
php artisan test --filter=ReservationFinancePhase24Test
php artisan test
php artisan route:list --path=api
```

Run on isolated test databases and inspect migrations on both PostgreSQL 16+ and MySQL 8.4/InnoDB. Test simultaneous requests for payment reference uniqueness and cancellation versus payment recording. Check local regulatory invoice, VAT/service-charge, refunds, cash handling, and fiscal records with the hotel and accountant **before any production payment use**.

## Still missing / next steps

- Taxes, service fees, receipts, official invoicing, proper final-payable totals
- Gateway/bank verification and reconciled processor events
- Refund and reversal of received funds, explicit payment refund ledger
- Rate re-quote, amendments and charge differences for priced stays
- Daily cash reconciliation, export, audit/reporting
- Real frontend finance adapter and customer-facing billing/checkout

Next proposed **Phase 26**: verified payment-provider reconciliation, tax and cancellation/refund configuration, and frontend read-only folio integration. Neither this milestone nor staff confirmation authorizes claiming actual bank settlement.
