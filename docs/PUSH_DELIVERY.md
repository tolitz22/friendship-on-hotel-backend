# PN-3 pilot worker

Disabled by default. The production API must not send Web Push inline.

Before enabling, install the maintained transport using Composer in a PHP 8.3+ environment:
`composer require minishlink/web-push`
Commit both composer.json and composer.lock, run backend CI, and deploy the identical revision to the API and worker. The transport dependency is intentionally not added to the manifest alone because the lock file must be resolved together.

Generate a VAPID key pair using the installed library's VAPID::createVapidKeys() in a secure local shell. Set PMS_PUSH_PUBLIC_KEY, PMS_PUSH_PRIVATE_KEY and PMS_PUSH_SUBJECT (mailto: or HTTPS contact), PMS_PUSH_HOTEL_ID (Client Demo only), and PMS_PUSH_ENABLED=true on a dedicated worker only. Share database and APP_KEY with API. Never expose the private key.

Railway worker start command: php artisan schedule:work
Run migrations on the API deployment before enabling the worker. No volume required. Keep one worker for the pilot.

The database outbox deduplicates per subscription and alert ID, retries up to five times, and checks active admin membership at delivery. Delivery is at-least-once; client-side notification tags will be required. Existing alerts may be sent on first activation. This polls current alerts, not a durable business event stream. Ordinary logout intentionally preserves device opt-in. Use explicit device removal or shared-browser reset to stop it. Active administrator membership is rechecked at delivery. Verify ownership concurrency and actual browser navigation before real-hotel activation.

## PN-8.4 reliability

Queue work is scoped to the pilot hotel. Claims are fenced by attempt number and five-minute lease; expired claims cannot finalize a newer attempt. Five attempts maximum, exponential retry with provider Retry-After, and a 24-hour queued-delivery deadline. Urgent alerts sort first among fresh work; older work is promoted after 15 minutes. Provider 404/410 removes the expired subscription (its delivery rows cascade); other permanent failures record a generic failure code. Worker output reports aggregate delivery status counts only. No provider payloads or secrets are logged. A provider accepting a send before a process crash can still result in a repeat; exactly-once delivery is not guaranteed.

## PN-8.5 performance

Discovery loads eligible user IDs and preferences in batches of 100, reads only device IDs/user IDs, skips fully opted-out batches, discovers personal alerts once per recipient, and inserts up to 100 delivery rows per SQL statement. Delivery still rechecks live preferences and eligibility immediately before transport. The scheduled daily prune removes at most 1,000 terminal immutable short-window event records older than 30 days per run for the pilot property only. Persistent operational blockers and mutable-state events retain their deduplication records so old unresolved issues do not notify again after pruning. No guest/financial/audit source records are removed. Indexes support scoped queue lookup and terminal history retention. Actual query plans, load and Railway costs must be measured during pilot acceptance.

## PN-8.8 preflight and release gate

Run `php artisan pms:push-check --scan` with delivery disabled. This is read-only and will report missing transport, configuration or schema without printing secrets. Client Demo is the default allowed scope; `PMS_PUSH_ALLOW_NON_DEMO` must remain false until explicit rollout approval. See [release readiness and rollback runbook](PUSH_RELEASE_READINESS.md). Passing preflight is necessary, not sufficient for live activation.
