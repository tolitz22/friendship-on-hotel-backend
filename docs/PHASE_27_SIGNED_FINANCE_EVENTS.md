# Phase 27 — Signed Event Inbox & Reconciliation Gates

Status: source changes on backend `main`. **Not a live bank or payment-provider integration.** PHP/Composer tests, MySQL/PostgreSQL concurrency, deployment and provider certification remain unverified.

## Scope

- Signed HMAC-SHA256 finance event inbox with timestamp freshness, exact raw-body signature verification, strict payload allowlist, small request limit, provider+event-ID uniqueness, and idempotent duplicates.
- **Disabled by default.** No provider is chosen, and the `custom_hmac_v1` contract is a **project-specific test/adapter protocol**, NOT compatible with PayMongo, Xendit, Stripe, Maya or any bank unless a provider-specific adapter is separately implemented and tested.
- Incoming `payment.observed` and `refund.observed` events are stored as **unmapped observations**. No events directly change `folio_payment_records.status`, folio accounting, refund requests, reservation state, or bank settlement.
- Hotel administrators can link a `payment.observed` event to existing property-scoped payment evidence if **reference, amount and folio currency** match. A match is auditable but **not external payment verification**, since the generic HMAC credential and provider account have no bank identity or per-hotel ownership guarantee.
- Fiscal charge policy drafts may now be independently reviewed by another administrator; reviewed status is **still non-effective and legally unvalidated**. No tax rates are activated on bookings or folios. Prior historical amounts remain untouched.

## Disabled-by-default signed test adapter

Route is public for webhook transport but rejects events unless the adapter is explicitly enabled and has a strong 32+ byte shared secret:

```http
POST /api/v1/integrations/finance/custom-hmac-v1/events
X-Finance-Timestamp: 1794918000
X-Finance-Signature: sha256=<64 lowercase hexadecimal digits>
Content-Type: application/json
```

Development-only environment variables:

```dotenv
FINANCE_CUSTOM_HMAC_ENABLED=false
FINANCE_CUSTOM_HMAC_SECRET=
```

**Never put real keys in the repository, log output, frontend bundles, or documentation.** The required signature is

`sha256=` followed by `HMAC_SHA256(secret, timestamp + "." + raw_request_body)`.

Use an absolute Unix timestamp (seconds), with a maximum allowed skew of 300 seconds. The only accepted JSON shape, in the **exact field order** below, is:

```json
{
  "eventId": "example-event-0001",
  "eventType": "payment.observed",
  "reference": "EXAMPLE-REFERENCE-001",
  "amountMinor": 150000,
  "currency": "PHP",
  "occurredAt": "2026-11-17T15:00:00+08:00"
}
```

Also accepted is `refund.observed` for **storage only**; it cannot be matched to a payment. The transport does not accept additional metadata or identity information, and it stores only those allowlisted fields. The global provider-name namespace is `custom_hmac_v1`; it does not assert a real processor origin. Identical event-ID + body deliveries return 200, new events return 202, reused IDs with different content return 409. Invalid signatures or timestamp windows return 401; an unconfigured adapter returns 503. Events are append-only from this interface.

**Provider onboarding checklist:** replace the generic signing scheme with the chosen provider's documented signature/version/replay verification, validate the merchant/subaccount, currency exponent, sandbox/live keys, event schema, event ordering, refund lifecycle and authoritative source-of-truth API. Require authentic provider sandbox fixtures and independent integration tests. **Do not turn on this generic adapter as if it were a real webhook integration.**

## Admin reconciliation endpoints

Under `/api/v1/hotels/{hotel}`:

```http
GET  /bookings/{booking}/finance-event-reviews
POST /bookings/{booking}/finance-event-reviews
POST /finance/charge-policy-drafts/{policy}/review
```

The event-review POST accepts:

```json
{
  "eventId": 1,
  "paymentRecordId": 2,
  "note": "Matching reference, currency and nominal amount only"
}
```

It requires a priced folio and hotel-scoped payment, an unreviewed observed-payment event, matching reference and amount/currency; it records the reviewer without changing payment state. Never display `reference_match` as "paid", "verified" or "settled". `eventId` here is the **internal inbox ID** returned when the signed event was ingested; do not confuse it with a provider event ID.

Fiscal review:

```json
{"decision":"approve","note":"Approved for internal review, statutory validation pending"}
```

Only a **different hotel administrator** may approve an authored draft. Status becomes `reviewed` (or `rejected`); `effectiveForBookings` and `legallyValidated` remain false and no quote/folio writes occur.

Existing Phase 26 refund requests remain internal approvals only. No endpoint transfers funds.

## Verification

Against an isolated test database:

```bash
git pull origin main
php artisan migrate
php artisan test --filter=FinanceReconciliationPhase27Test
php artisan test --filter=FinancePolicyPhase26Test
php artisan test
php artisan route:list --path=api
```

Run a separate fresh migration and truly concurrent event-ID duplicate delivery test on **MySQL/InnoDB and PostgreSQL**, in addition to SQLite tests. Verify signed-body tampering, stale timestamps, duplicate conflicting event IDs, cross-tenant access, incorrect currency/reference/amount, immutable source amounts, and audit. Verify no sender ever causes automatic paid/refunded/settled status.

## Deferred: production payment and tax activation

Provider-specific verified webhook adapter, merchant-to-hotel identity binding, real payment state machine, idempotent settlement postings, refunds and payout verification, tax-inclusive/exclusive and exemption rules, statutory invoice requirements, and accounting approval of fiscal policy activation are **not implemented**. Production activation must not be inferred from a successful generic HMAC test.

**Next proposed Phase 28:** implement the *chosen provider's* sandbox adapter, signed webhook integration tests, correct merchant-level hotel binding, and provider reconciliation; separately implement accountant-approved tax-policy application and immutable per-booking tax snapshots.
