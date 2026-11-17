# Phase 28 — PayMongo Test-Mode Webhook Adapter

**Status:** Source committed on backend `main`. Not a deployed PayMongo merchant integration, payment collection, tax-complete billing system, or verified settlement implementation. No merchant credentials are stored in Git.

## Provider chosen for the sandbox interface

This milestone uses PayMongo's documented **test-mode** webhook signature contract instead of treating the custom generic HMAC adapter as a real payment provider. The `Paymongo-Signature` header has a timestamp (`t`), a test signature (`te`) and a live signature (`li`). The test signature is HMAC-SHA256 over the timestamp, a dot, and the raw JSON body using the **test webhook signing secret**.

Official references:
- https://docs.paymongo.com/docs/developer-tools-webhook-setup-management
- https://docs.paymongo.com/docs/developer-tools-webhooks-events
- https://docs.paymongo.com/docs/payment-channels-hosted-checkout-quick-start

## Safe defaults

- `PAYMONGO_SANDBOX_WEBHOOK_ENABLED=false` by default.
- Requires an endpoint-specific test signing secret and explicit `PAYMONGO_SANDBOX_HOTEL_ID`; the configured mapping is server-controlled, **never accepted from an event field**.
- Validates raw-body signature, timestamp (5-minute window), event envelope and `livemode:false`.
- Rejects live deliveries even if a test signature is supplied.
- Persists one event per PayMongo event ID using a database unique key. Replays with identical raw payload return 200; reused IDs with conflicting payloads return 409.
- Stores only event ID, type, session/resource ID, short reference, SHA-256 fingerprint and hotel ID. No raw webhook JSON, payment card, customer billing information or credentials.
- Event status remains `unmapped`. **No payment record, folio adjustment, settlement status, tax activation, refund, or reservation state is updated.**
- Admin-only, tenant-scoped event listing supports inspecting the test observations.

### Endpoints

```http
POST /api/v1/integrations/paymongo/sandbox/events
GET  /api/v1/hotels/{hotel}/finance/paymongo-sandbox/events
```

The inbound webhook route must be registered in the PayMongo **test-mode dashboard** using its distinct webhook secret. It is not an authenticated staff API; authenticity comes from the test-mode signature verification. The read endpoint requires an active hotel administrator.

### Environment

```dotenv
PAYMONGO_SANDBOX_WEBHOOK_ENABLED=false
PAYMONGO_SANDBOX_WEBHOOK_SECRET=
PAYMONGO_SANDBOX_HOTEL_ID=
```

Only enable on an isolated sandbox/staging deployment with a known, authorized test merchant account and its corresponding hotel ID. Do not copy a **live** signing secret into this setting. Never expose webhook secrets to React.

## Why outbound checkout is not activated

PayMongo's Hosted Checkout v2 provides `POST /v2/checkout_sessions` and returns `data.attributes.checkout_url`. However, this hotel PMS still lacks a tax-complete final payable amount and an approved payment/deposit policy. Automatically charging only the Phase 24 room subtotal would create a financial discrepancy, even if a test UI seemed to work. Therefore **no outbound PayMongo checkout or real payment capture is enabled in Phase 28**.

Before outbound checkout, implement hotel-approved taxable line items, charge snapshots, final payable totals, provider payment-method and success/cancel URL policies, durable local checkout idempotency, signed payment reconciliation, and explicit refunds/partial payments.

## Verification

From the backend repo:

```bash
git pull origin main
php artisan migrate
php artisan test --filter=PayMongoSandboxPhase28Test
php artisan test
php artisan route:list --path=api
```

Tests are written but have not been run against this project in the connected environment. Verify on SQLite and both target database engines (MySQL 8.4 and PostgreSQL 16+). In PayMongo test mode, configure the webhook URL using HTTPS and send official test events; compare actual payloads and headers against the adapter. Run duplicate-delivery and signature-tampering tests, plus test that no real funds/folios change.

## Remaining gateway milestone

Choose and connect a real authorized PayMongo sandbox merchant and tax/deposit policy; implement a durable checkout-intent table, server-only outbound checkout API calls, reconciliation on `checkout_session.payment.paid`, verified provider reference/amount/currency and merchant binding, and refunds. None of those are implied by the event-inbox code.
