# Phase 26 — Draft Charge Policies, Refund Review, and Read-Only Live Finance

**Committed directly to backend `main` and frontend `main`.** These changes are application foundations, **not a production-certified payment or tax system**.

## Scope and default decisions

- **Fiscal policies are versioned, non-effective drafts.** Admins may store hotel-scoped `taxBasisPoints` and `serviceBasisPoints` (0–10,000 basis points), human-readable labels, and a mandatory rationale. A stable hotel-row lock serializes version allocation. There is **no law-based default tax rate**.
- The charge preview computes hypothetical, exclusive add-on amounts from the room subtotal with exact integer, half-up rounding; it does **not** alter a booking, tax return, issued invoice, folio or payment liability. Tax-inclusive base rates and fee-on-tax are not implemented. Draft rates do not become active.
- **Refund requests are staff review records**, not actual payout attempts. An admin can request an amount against an existing **staff-confirmed payment record** in the same hotel's priced folio, with a unique request key and reason. Outstanding `requested` and `approved` amounts together cannot exceed the related payment's recorded amount. Rejected requests release that review allowance.
- The original requester **cannot approve** their own request. A second administrator may mark it `approved` or `rejected` once, with a required review note. Approved means internal authorization only; **no funds are transferred**. There is deliberately no `paid` or `refunded` status in this milestone.
- Staff confirmation of a payment remains unverified externally. No bank, gateway, webhook secrets, payout provider, or official fiscal receipt is connected. The existing provisional difference and `isSettled:false` semantics remain unchanged.
- The new frontend API-mode Billing lookup uses the **existing authenticated, selected-hotel session** to display a real folio ledger for a manually entered reservation ID. It is read-only and clearly distinct from the demo folio table; it does not silently switch to fixtures on errors.

## Routes

All paths are scoped under `/api/v1/hotels/{hotel}`:

```http
GET  /finance/charge-policy-drafts
POST /finance/charge-policy-drafts
GET  /bookings/{booking}/charge-preview?policyId=123
GET  /bookings/{booking}/refund-requests
POST /bookings/{booking}/refund-requests
POST /bookings/{booking}/refund-requests/{refund}/review
```

Hotel members may read; only active **hotel administrators** with `hotel:write` may mutate.

Charge policy draft example:

```json
{
  "taxBasisPoints": 1200,
  "serviceBasisPoints": 1000,
  "taxLabel": "Hypothetical tax",
  "serviceLabel": "Hypothetical service charge",
  "reason": "Accounting review only, not a legal tax rate"
}
```

Refund request example:

```json
{
  "requestKey": "refund-request-00001",
  "paymentRecordId": 7,
  "amountMinor": 25000,
  "reason": "Guest requested refund review"
}
```

Review example:

```json
{"decision":"approve","note":"Internal approval, awaiting actual refund fulfillment"}
```

A bank or processor refund is **never** executed by those endpoints.

## Migration

`database/migrations/2026_11_17_260000_create_charge_policy_versions_and_refund_requests.php` creates `hotel_charge_policy_versions` and `folio_refund_requests`, with version uniqueness, idempotency uniqueness, actors, amount, state and parent foreign keys.

## Validation

```bash
git pull origin main
php artisan migrate
php artisan test --filter=FinancePolicyPhase26Test
php artisan test --filter=FolioAccountingPhase25Test
php artisan test
php artisan route:list --path=api
```

Run tests only against an isolated test DB. Repeat migrations and concurrency verification on **MySQL 8.4/InnoDB and PostgreSQL 16+** before any production exposure. Verify identical refund-key replay, conflicting key reuse, cross-hotel access, independent approval, over-request prevention, and rounding.

In the frontend repository:

```bash
git pull origin main
npm ci
npm run typecheck
npm test
npm run build
```

Set `VITE_DATA_SOURCE=api` and authenticate/select a permitted hotel to see the **live read-only reservation folio lookup**; the existing table remains intentionally sample/demo.

## Outstanding before financial launch

- Statutory tax rules (inclusive/exclusive, exemption, VAT, service charges, fiscal receipt and jurisdiction)
- Activation and version snapshots of approved hotel fiscal policies, item-level tax allocation and tax-complete invoice
- Real payment provider credentials, signed webhook verification, retries, disputes, settlement reconciliation and payout tracking
- Refund execution, external refund reference/settlement evidence and approved cancellation/refund policy
- Official final balance and accounts receivable, daily close/night audit and accounting exports
- Real multi-process integration test results; frontend/browser acceptance and accessibility checks

**Suggested Phase 27:** explicitly selected payment provider adapter and signed webhook ingestion, tax policy activation gates and true settlement/refund reconciliation, after the hotel selects its bank/acquirer or payment service and applicable tax treatment.
