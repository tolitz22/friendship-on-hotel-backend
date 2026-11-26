# PN-8.8 — Push release readiness and PN-9 runbook

Assessment baseline: backend 59117db4b89e238c082b88678d66fdfdbb63b3fe, frontend 666f17304a81c5d909939bda58c12816f3705e2f. Decision: NO-GO for real-hotel push activation. No Railway changes or live sends performed.

## Release gates

- [ ] Latest exact backend revision passes quality checks and complete SQLite, PostgreSQL 16 and MySQL 8.4 CI (including migrations and notification tests).
- [ ] Latest exact frontend revision passes typecheck, Vitest, build and Playwright.
- [ ] Resolve minishlink/web-push with Composer on a supported PHP environment; commit composer.json and composer.lock together and rerun CI. Confirm sendOneNotification, response/Retry-After methods, provider TTL/topic, encryption and TLS behavior against installed version.
- [ ] Backup database, verify migration status and shared APP_KEY. Migrate once from API deployment, not concurrently from the worker. No production fixture seeding.
- [ ] Run read-only `php artisan pms:push-check --scan` in the worker environment while disabled. This checks key shape, contact syntax, dependency, schema, scope and a bounded sample of source queries; it does not verify a matching elliptic-curve key pair, delivery, every record or every browser.
- [ ] Verify all 41 notification kinds individually, including failed/rolled-back transactions, repeated events, stale conditions, recipient and tenant isolation, deep links and financial exception semantics.
- [ ] Client Demo pilot: explicit device opt-in, actual logged-out native push, permission revocation and repair, cross-account switch, membership/role revocation, retries, rate limits, expired endpoint, crash recovery, duplicate behavior, mobile and desktop device matrix.
- [ ] Verify delivery diagnostics and latency, query load and Railway resource use. Provider acceptance does not prove display.
- [ ] Review pilot evidence and obtain explicit approval before any non-demo rollout.

## Configuration and rollout

1. Keep PMS_PUSH_ENABLED=false everywhere while preparing. Install dependency in the backend repository, deploy identical revision to API and worker. Generate VAPID keys securely using installed transport. Never print the private key or store it in frontend.
2. Configure a dedicated Railway service using the backend repository and main branch, same database connection and APP_KEY as the API, no public domain and no volume. One replica. Start command: php artisan schedule:work.
3. Worker variables: PMS_PUSH_ENABLED=false, PMS_PUSH_HOTEL_ID=<verified Client Demo id>, PMS_PUSH_ALLOW_NON_DEMO=false, PMS_PUSH_PUBLIC_KEY, PMS_PUSH_PRIVATE_KEY, PMS_PUSH_SUBJECT. The non-demo override defaults false and requires explicit approval before changing. Do not assume the pilot hotel ID.
4. Frontend build variable: VITE_PUSH_PUBLIC_KEY=<same public key>. Rebuild frontend. Register only trusted test devices for Client Demo.
5. Run preflight with --scan. Check no secrets in logs. If gates pass, enable worker flag only and observe. First activation can discover existing alerts from the current discovery window and persistent blockers; use an isolated demo and inform test admins. Do not enable API flag.
6. Follow frontend docs/PUSH_BROWSER_ACCEPTANCE.md for browser tests. Record evidence, not just code presence. Do not activate actual client hotels without separate approval.

## Rollback and incident response

1. Disable PMS_PUSH_ENABLED on the worker and stop worker service; redeploy/restart to apply environment changes. Keep the API available. Already accepted provider messages may arrive until their TTL expires.
2. If a specific browser is compromised, remove its registration and revoke browser push permission; for account or role concerns deactivate/revoke the appropriate membership. Preserve normal logout behavior.
3. Preserve queue records and financial audit data for investigation. Do not truncate or drop push tables merely to stop delivery; do not roll back financial migrations. Reverting code requires compatibility review. Re-enable only after root cause and replay/deduplication risks are addressed.
4. Log only sanitized codes and aggregate counts. Never include endpoint URLs, subscription secrets, VAPID private keys, or confidential message/financial payloads.
5. No exactly-once guarantee across transport and database; polling is not a durable event stream. Short-window events may be missed during prolonged outages. Historical mutable-state alerts and unverified module-level deep links remain acceptance items.

## Current evidence

At assessment, latest full backend validation and latest frontend CI were not complete. Earlier frontend UI/UX validation and quality gate passed. Composer dependency absent in manifest. No actual Railway worker or live-device evidence available. Do not treat this document or preflight success as final production approval.

## All-property mode (supersedes single-hotel activation configuration above)

The worker now supports `PMS_PUSH_SCOPE=all`. In this mode omit `PMS_PUSH_HOTEL_ID` and `PMS_PUSH_ALLOW_NON_DEMO`; those settings apply only to legacy `pilot` mode (the default). One worker handles properties with registered devices individually. Newly created properties are included automatically when an administrator opts in. Device eligibility, preferences, message discovery, queue queries and delivery revalidation remain scoped to the individual property. One property failure does not prevent processing the others. Preflight reports aggregate counts for all properties and scans at most ten hotels with --scan. Keep PMS_PUSH_ENABLED=false until dependency, CI and live Client Demo acceptance pass. For live testing only, use pilot scope and the verified Client Demo ID. After acceptance use all scope with explicit activation approval. Review multi-property throughput before broad activation.
