# No-show financial pilot acceptance (NS-F7.1.4)

Status: NOT EXECUTED. This runbook is not activation approval.

## Preconditions

- Record deployed frontend/backend commit SHAs and successful CI run URLs for those exact revisions (including PostgreSQL, MySQL 8.4 and browser tests).
- Confirm the target hotel's numeric ID and exact Client Demo identity. Never infer an ID or use another property. Use synthetic bookings, payments and evidence only; never transfer real funds.
- Prefer an isolated deployment and disposable database. On shared production, do not enable global flags until property scoping and existing enrolled hotels have been reviewed. Do not overwrite an existing allowlist or enable verified settlement globally merely for this test.
- Record existing configuration, backup/recovery readiness, financial business date, open accounting period, open shifts and two independent active administrators. Do not expose credentials.
- Run `php artisan finance:inspect-no-show-pilot HOTEL_ID` from the deployed backend runtime. Record JSON and exit code. The preflight checks the required feature flags for verified settlement, bank deposit posting, allocations, refunds, fee corrections and closing integrity. Activation still requires the release evidence below. Run `php artisan finance:inspect-no-show-journals HOTEL_ID` before and after the exercise. Stop on discrepancies.
- Use a synthetic reservation whose arrival cutoff has elapsed without manipulating a shared financial clock. If this cannot be arranged safely, use isolated staging. Do not close a shared hotel date for a test.

## Acceptance matrix

Record actor IDs, booking/payment/refund/journal identifiers, request outcome, amounts and evidence links for each row. All rows start NOT RUN.

| ID | Test | Expected |
| --- | --- | --- |
| A01 | Priced no-show with PHP 6,000 original room subtotal and PHP 2,000 fixed penalty | Room released; original folio history preserved |
| A02 | Record adjustment as admin A; attempt self-approval; approve as admin B | Self-approval denied; one effective clearance |
| A03 | Independently verify synthetic PHP 3,000 bank deposit; recognize and retry | Exactly one receipt journal; no new collection |
| A04 | Allocate PHP 1,200, then PHP 800; repeat identical request key | Two allocation journals; replay does not create a third |
| A05 | Attempt refund PHP 1,000.01; request PHP 1,000 as admin A; approve as B | Over-limit rejected; single approved request |
| A06 | In isolated test context only, record synthetic external bank reference; reject same-recorder verification; verify as independent admin | Exactly one refund journal; PMS sends no funds |
| A07 | Reconcile settlement | Deposits 300000, fee 200000, allocations 200000, refunds 100000 minor units; both balances zero; reconciled and settled |
| A08 | Separate synthetic booking: reduce VAT-inclusive PHP 2,000 fee to PHP 1,000 and independently approve/post | Revenue 89286 and VAT 10714 minor units; original journal preserved; correct reversal and replacement |
| A09 | Separate synthetic booking: full fee waiver | Effective fee zero; reversal only; no automatic refund |
| A10 | Reception and excluded property access | Restricted endpoints denied; no financial changes |
| A11 | Closing readiness on synthetic property | Missing journal/pending correction identified; clean case clears integrity blocker; ordinary closing checks remain |
| A12 | Close only a disposable, explicitly approved test business date | Date advances exactly once; immutable balanced closing snapshot; otherwise mark NOT RUN |
| A13 | Desktop/mobile workflow and refresh failure | No duplicate writes, no misleading settlement status, actionable error recovery |

Do not corrupt live journals to manufacture blockers; use the automated database regression suite for deliberate corruption cases.

## Release evidence

For each row record PASS / FAIL / BLOCKED / NOT RUN, expected and actual result, evidence reference, reviewer and timestamp. A screenshot alone does not prove journal correctness. Reconcile source records, journal counts and debit/credit totals. Never mark a synthetic reference as proof of a real bank transfer.

## Stop and recovery

Stop writes for unexpected balances, unauthorized access, duplicate journals, stale deployment, or failed independent review. Preserve evidence and notify the finance owner. Do not delete posted journals, reverse entries manually, rewind business dates or blindly remove an enrolled hotel from the allowlist: removing the hotel also disables extended closing enforcement. Keep unresolved financial records protected from closing until reconciled. Obtain an approved recovery plan and repeat preflight before resuming.

## Gate

GO for controlled pilot only after exact deployed revisions pass CI, actual configuration is inspected, acceptance evidence is complete, no unresolved financial integrity issues remain, and independent finance approval is recorded. Cash and historical deposit posting require separate acceptance. Production activation is a separate decision. No feature flags are changed by this document.
