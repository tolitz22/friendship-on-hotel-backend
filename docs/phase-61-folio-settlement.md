# Phase 61 — Guest folio, documentary settlement and checkout control

## Scope implemented

- **Existing folio is canonical**: original `reservation_folios`,
  `folio_entries`, `folio_adjustments`, `folio_payment_records`,
  cashier shift receipts and the reviewed refund request records remain
  authoritative. This phase does not create a second room-charge engine.
- **Itemized statement**: room nights, adjustments, Phase 60 VAT allocations
  when available, staff payment evidence, verified allocation by payer,
  unallocated deposits, refund history and signed financial snapshot.
- **Payment evidence review**: independently reviewed bank statement evidence
  or independently reconciled cashier shift evidence; separate preparer and
  reviewer IDs. Staff confirmation is never automatically promoted.
- **Deposits and payer attribution**: reviewed payments remain deposits until
  allocated to the guest, company or group. Allocations are idempotent and
  cannot exceed the original payment, charge line, or amounts reserved by
  refund requests. Payer attribution is NOT an invoice to that legal payer.
- **Refunds**: existing two-person refund request/approval, plus independent
  review of evidence for an *external* bank refund of unallocated verified
  deposits. The PMS does **not** send funds. Approved refunds without reviewed
  evidence block settlement.
- **Sign-off**: manager-only and irreversible, only for a checked-in guest at
  or after scheduled departure with a fully reconciled account and no
  unallocated deposits or unresolved refunds. The signed snapshot is hashed.
  Any out-of-band changes to charges, adjustments, payments, allocations,
  refunds or tax allocation are detected and block checkout.
- **Checkout**: with the new feature enabled, the existing booking lifecycle
  requires a valid signed folio and current reconciliation. Existing checkout
  keycard and housekeeping turnover behavior is retained.

## HTTP endpoints

Hotel-scoped, authorized routes:

```text
GET  /api/v1/hotels/{hotel}/bookings/{booking}/settlement-statement
POST /api/v1/hotels/{hotel}/bookings/{booking}/payment-records/{payment}/verification
POST /api/v1/hotels/{hotel}/bookings/{booking}/settlement-verifications/{verification}/review
POST /api/v1/hotels/{hotel}/bookings/{booking}/payment-records/{payment}/allocations
POST /api/v1/hotels/{hotel}/bookings/{booking}/settlement-signoff
GET  /api/v1/hotels/{hotel}/bookings/{booking}/refund-disbursements
POST /api/v1/hotels/{hotel}/bookings/{booking}/refund-requests/{refund}/disbursement-evidence
POST /api/v1/hotels/{hotel}/bookings/{booking}/refund-disbursements/{evidence}/review
```

Read-only settlement statements use existing `finance.read` permissions.
All settlement mutations run under hotel-admin permissions with rate limits.

## Frontend

Go to **Billing** and look up the reservation ID.
The live panel includes printable A4 statements, reviewed payment and refund
evidence, payer allocations, deposit remainder and manager sign-off controls.
Prints explicitly say they are *not* BIR invoices, receipts or external bank
confirmations. Tampered signed statements cannot be printed as approved.

## Safe rollout

The feature is **off by default**:

```env
FINANCE_ENABLE_VERIFIED_SETTLEMENT=false
```

Keep OFF in production until staging database migrations, full application
tests, legacy folio/payment reconciliation and finance sign-off have passed.
The Phase 60 setting `FINANCE_ENABLE_LIVE_FOLIO_TAX` remains independently
controlled. Both features require coherent tax/cash accounting policies.

Apply the new migrations only after a backup and review using a disposable
staging database. No live payment or refund instruction is sent by this code.

```bash
php artisan migrate --pretend
php artisan migrate
php artisan test --filter=FolioSettlement
php artisan test --filter=FolioNight
php artisan test --filter=FinancialBusinessDay
php artisan test
```

To verify real database locking, use a disposable MySQL/InnoDB or PostgreSQL
test database, `APP_ENV=testing`, and `FINANCE_REAL_DB_LOCK_TESTS=1`:

```bash
php artisan test --filter=FinancialRealDatabaseConcurrency
```

The test runner in this environment has NOT executed these commands. Railway
deployment success alone does not prove PHP feature tests passed.

## Remaining production requirements

- Real PSP/bank statement feed for **automated** verification; current
  verification is dual-reviewed documentary evidence only.
- Cash refund payout processing and cashier shift payout tracking.
- Legally correct BIR tax invoices/credit memos and approved final tax points.
- Full company/group debtor accounts and individual debtor invoices.
- Refund evidence rejection re-submission and reviewed post-checkout
  amendment workflow (currently requires manual investigation).
- Historical balance reconciliation/backfill, load/concurrent checkout tests
  on the production-equivalent engine, and financial compliance sign-off.
