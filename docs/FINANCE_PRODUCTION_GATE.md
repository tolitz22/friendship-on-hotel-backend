# Financial activation and production-engine gates

This document records open risks. Do **not** treat a passing unit test or a
staff-reviewed payment as a bank settlement or a final statutory tax invoice.

## Already implemented for verification

- Group allotment expiration preservation: cross-engine migration changes
  MySQL/MariaDB `expires_at` to non-auto-updating DATETIME. Release and
  conversion tests compare the original timestamp before and after writes.
- CI baseline: settlement-disabled full feature suite, per-file isolated
  MySQL 8.4 and PostgreSQL 16 suites.
- CI settlement-enabled: focused `FolioSettlementIntegrationTest` runs on
  SQLite, MySQL 8.4 and PostgreSQL 16, with per-file disposable database
  isolation. **Not yet** an entire settlement-enabled full-suite pass.
- CI concurrency: a six-process booking race competes for one physical room
  on the real MySQL/PostgreSQL services. **Not yet** proof of checkout,
  readiness, allocations, or sign-off race safety.
- Housekeeping: manager handoff queue, age/escalation indicators, staff in-app
  assignment polling. These do not automatically mark rooms ready.

## Blocking before activating verified financial settlement

1. Resolve legacy checkout test fixtures to prepare the required signed
   folios under `FINANCE_ENABLE_VERIFIED_SETTLEMENT=true`. Run the **full**
   suite on both true and false, on all supported engines.
2. Refactor settlement integration fixtures further to validate production
   migrations and constraints in addition to the isolated miniature-schema
   service-contract test.
3. Run independent multi-process race tests for room readiness approvals,
   checkout, payment allocations, refund review, and immutable sign-off.
   Check transaction locks, idempotency, deadlocks, and no double posting.
4. Establish provider/bank-side payment verification, independently verified
   refund disbursement, and signed/replayed webhook handling with monitored
   reconciliation. Internal documentary review is not external settlement.
5. Complete Philippine VAT and legally approved invoicing totals, benefits,
   receipt/invoice numbering, correction journals and accounting sign-off.
6. Approve written cashier, duty manager, housekeeping handoff, financial
   close and incident-response procedures before enabling live writes.
7. Configure delivery and acknowledgments for housekeeping notifications
   (email/push or on-site notification infrastructure). The current feed
   only polls while users have the workspace open.
8. Confirm all new migrations and CI jobs have passed in staging using the
   same RDBMS engine/version as production; verify backups and rollback plans.

Until these gates are closed, keep `FINANCE_ENABLE_VERIFIED_SETTLEMENT` and
`FINANCE_ENABLE_LIVE_FOLIO_TAX` off in production unless a formally reviewed
activation plan explicitly authorizes otherwise.
