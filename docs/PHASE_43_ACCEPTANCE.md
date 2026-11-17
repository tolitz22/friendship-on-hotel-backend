# Phase 43 — Real workflow acceptance

The source of truth for this gate is `tests/Feature/Phase43RealWorkflowAcceptanceTest.php`.
The test uses Laravel's `RefreshDatabase` under Pest; it exercises **real HTTP routes, services and persisted database rows**, but never creates live hotel guests or collects actual money.

## Run

```bash
composer install
php artisan test tests/Feature/Phase43RealWorkflowAcceptanceTest.php
php artisan test
```

The backend GitHub Actions workflow runs the named Phase 43 acceptance step on SQLite, and the complete suite (including Phase 43) on SQLite, PostgreSQL 16 and MySQL 8.4. No staging secrets are needed for these disposable database tests.

## Assertions covered

1. Create a synthetic guest and a priced one-night hotel rate.
2. Book the stay through the protected HTTP API; verify status and idempotent replay.
3. Attempt to oversell the sole room: the second booking returns 409 with no additional reservation.
4. Assign the real physical room and check in on the hotel-local arrival date.
5. Reject an early checkout.
6. Verify the persisted room-night folio and integer-minor-unit charge.
7. Record synthetic cash payment evidence, replay its request key, and confirm exactly one record.
8. Review the payment and verify the summary: staff-confirmed payment is **not** processor settlement.
9. Check out on the hotel's departure date; verify lifecycle, actor-attributed events, and exactly one checkout-clean housekeeping turnover.
10. Reject cross-property access and non-admin booking writes, with no unauthorized booking created.

## What passing this does NOT prove

- A true browser-driven (Playwright) end-to-end workflow, deployment, actual staging URL, device ergonomics, cookie/CSRF session flow or front desk UX
- External payment settlement, taxes/fees, refund policy, final payable amount, reconciliation or independent cash counting
- Parallel-request concurrency under MySQL/PostgreSQL row locks; one sequential oversell attempt is not a concurrency stress test
- Independently enforced occupancy through hotel keycard controllers or CCTV

**Cash fraud safety:** A `staff_confirmed` payment status records an internal staff review; it is not a bank or gateway receipt. Never treat a provisional difference of zero as verified settlement.

## Next acceptance gate — isolated staging browser flow

Provision a dedicated HTTPS staging frontend/API, disposable hotel data, non-production payment credentials and dedicated staging database. Run browser tests against this environment and verify saved records through backend API or database probes. Cover guest search/create, room selection, booking idempotency, assignment, check-in, payment evidence and review, and checkout/turnover with a simulated hotel clock. Use restricted test accounts and never run write tests on production.

Do not report Phase 43 complete until GitHub Actions passes on the commit that includes this suite, and the separate staging browser workflow is executed with evidence. No deployed staging environment has been verified by this code change.
