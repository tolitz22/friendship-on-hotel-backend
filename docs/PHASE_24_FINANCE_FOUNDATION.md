# Phase 24 — Nightly room rates and reservation folio foundation

**Source:** backend `main`. PHP/Composer migrations and test execution have not been verified. Not approved for production money collection.

## Implemented policy

- Administrators can create an immutable **fixed nightly room rate** for a selected room type. Each rate belongs to one hotel, room type, named plan and three-letter currency; only positive/zero minor-unit amounts supported. Later changes must use explicit new versions, not update historical amounts.
- Amounts are integers in **minor currency units** (e.g. `250000` means PHP 2,500.00 for PHP), not PHP floating point. Hotel currency is authoritative; a rate from another currency or property is rejected.
- A read-only quote multiplies one fixed nightly amount by number of half-open hotel-local nights. The endpoint does **not** infer taxes, tourism fees, service charges, discounts or deposits.
- When a booking includes `ratePlanId`, the existing booking transaction validates the chosen active rate and atomically stores a reservation folio and **one immutable room-night entry per booked night**. The rate, dates and currency are snapshotted independently of any future catalogue modifications.
- Existing booking requests without a rate plan are still accepted for backward compatibility but are **unpriced**, with no invented folio. The folio endpoint explicitly reports that absence.
- An idempotent retry cannot create a second folio or new charges. Reusing a booking key with a different rate plan is a conflict.
- Priced bookings cannot be date-amended or cancelled using existing no-finance endpoints until a complete repricing, charge reversal and refund policy is implemented. This prevents stale invoices and unreversed financial liabilities.
- Folio shows a **room subtotal only**, never a final payable total or a paid balance. No payment-provider connection, manual payment recording, confirmed revenue or invoicing/tax certificate is implemented in this milestone.

## New API

```http
GET  /api/v1/hotels/{hotel}/rate-plans
POST /api/v1/hotels/{hotel}/rate-plans
GET  /api/v1/hotels/{hotel}/room-quotes?roomTypeId=1&ratePlanId=1&checkIn=2026-12-10&checkOut=2026-12-12
GET  /api/v1/hotels/{hotel}/bookings/{booking}/folio
```

Rate plan creation requires active hotel administrator membership with `hotel:write`; reads require active hotel membership and `hotel:read`.

Example rate plan creation:

```json
{"roomTypeId":1,"name":"Standard BAR","nightlyMinor":250000}
```

To create a priced booking, add `"ratePlanId": 1` to the existing admin `POST /bookings` payload. Full quote/folio results use `currency`, `nightlyMinor`, `roomSubtotalMinor`, `nights`/`entries`, and explicit `taxesCalculated:false` and `isFinalPayableTotal:false` flags.

## Data

- `room_rate_plans`: scoped rate plans and published fixed nightly amounts
- `reservation_folios`: unique priced booking folio, currency and actor
- `folio_entries`: immutable individual room-night snapshots, scoped by hotel and folio, with the original rate plan reference

## Verification before use

```bash
git pull origin main
php artisan migrate
php artisan test --filter=ReservationFinancePhase24Test
php artisan test
php artisan route:list --path=api
```

Run migrations only against the intended database; run tests against an isolated test database. Test MySQL 8.4 and PostgreSQL 16+ constraint and concurrency behavior, particularly booking plus folio atomic rollback, scope validation, rate plan creation races, and idempotent retries. Check approved hotel tax and service fee requirements before issuing customer quotes.

## Remaining work

1. Versioned seasonal and day-of-week rates; promotional discounts; cancellation/repricing adjustments and negative ledger reversals
2. Tax/VAT and service-charge rules validated for the selected hotel's tax situation
3. Verified manual payments, gateway reconciliations, cash register and receipt/invoice issuance
4. Deposit and outstanding balance calculation only once the complete payable total is defined
5. Frontend rate/folio API integration with accurate financial disclaimers

**Next proposed milestone:** Phase 25 — rate policy versions, folio reversals, payment tracking and settlement boundaries. Do not present this Phase 24 subtotal as a final amount due.
