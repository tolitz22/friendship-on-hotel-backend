# Simplified Cashier Mode — controlled rollout

This optional policy is designed for routine **cash-only** front-desk checkout. It removes per-receipt manager confirmation and permits eligible guest checkout **before** independent end-of-shift reconciliation. It does **not** turn the cash receipt into an independently verified bank or finance settlement, tax invoice, or owner-reviewed drawer.

## Activation

Set on the **Laravel backend** only:

```dotenv
FINANCE_SIMPLIFIED_CASHIER_MODE=true
```

Default is `false` (strict legacy settlement enforcement remains in effect). Test with synthetic data on staging first. The separate `FINANCE_ENABLE_VERIFIED_SETTLEMENT` flag controls strict signed settlement paths, including bank/evidence workflows. Enabling simplified cash mode does **not** remove or relax bank verification, refund approvals, or the manager's independent shift reconciliation. Existing historical receipts with no recorded tender/change are **not** eligible for simplified checkout.

Do not activate in live financial operations until the owner approves the property SOP, tax-complete charges, statutory invoicing, history/backfill approach, permissions, backups, and finance reporting. Coordinate configuration deployment and `php artisan config:clear` / `php artisan config:cache` as appropriate. Never change the flag mid-shift without an operational plan.

## Cashier workflow

1. Open the cashier's **own** shift, counting the opening float.
2. Record the guest payment in Guest Billing. For an ₱8,400 bill and ₱10,000 tendered, save `cashTenderedMinor=1000000`, `changeGivenMinor=160000`, and `amountMinor=840000`. Give ₱1,600 change immediately.
3. The newly recorded cash payment becomes **staff-confirmed** (not independently verified). It is tied to the cashier's open shift; the backend prevents mismatched amounts and duplicate receipts.
4. If the whole guest folio is covered by auditable cash, has no refunds/manual adjustments/disputed evidence, and has no outstanding tax assessment constraints, the receptionist may follow the **routine cash checkout** path without waiting for shift closure.
5. The guest room remains subject to the normal reservation checkout, keycard revocation, housekeeping turnover and room readiness rules.
6. Cashier physically counts the whole drawer, including the opening float, submits the shift, and cannot record further cash against it.
7. **Another authorized manager/owner** reconciles the shift or flags the variance. Cashiers cannot reconcile their own shift, and managers cannot disguise a shortage by changing captured receipts.
8. Owner reviews Cashier Shifts > Cash control overview for submitted, flagged and reconciled drawers, opens linked guest bills and investigates all discrepancies.

Expected drawer cash = opening float + sum of **net valid cash receipts** (excluding voided mistakes). Tendered minus change equals the net receipt. Change given is not hotel revenue. End-of-shift reconciliation is separate from guest checkout.

## Exceptions that do not use routine cash checkout

- Bank transfer, mixed-method, unverified or pending/rejected payment evidence
- Incomplete cash receipt evidence, missing/invalid cash tender or change, unlinked or flagged cashier shift
- Bill changes, credits, discounts, refunds, overpayments, disputed amounts, outstanding tax assessments or signed statement integrity failures
- Missing/zero/negative recorded charges, under/over-paid accounts, already allocated funds or other signed settlement workflows

These require authorized finance review. Ordinary mutation after checkout is blocked; a separate reviewed post-checkout correction workflow is required. Voiding or refunding should never be used to disguise a physical cash shortage. Owner access to the dashboard is **not** a substitute for physically counting the drawer.

## Verification before production

```bash
php artisan test --filter=SimplifiedCashCheckoutTest
php artisan test --filter=PaymentDuplicateAndVoidTest
php artisan test --filter=FolioSettlementIntegrationTest
php artisan test
```

Validate checkout in staging with real MySQL 8.4 and PostgreSQL, including two simultaneous receipts, checkout versus cancellation, drawer close/manager review, tamper protection, signed statements and business-day locks. Check frontend production build and critical browser flows. The feature flag must remain off until these gates pass.

**Rollback:** set `FINANCE_SIMPLIFIED_CASHIER_MODE=false` and redeploy. Recorded payment and shift audit events are not deleted. Do not toggle back to strict enforcement during an active cash checkout without reviewing unfinished stays and historical folios.
