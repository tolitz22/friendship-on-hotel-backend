# Phase 55 — Independent Housekeeping Occupancy Coverage Report

## Purpose

Make Phase 53 observations useful for daily manager oversight without requiring any keycard, door sensor, or CCTV integration. This is a **coverage and discrepancy review**, not proof of wrongdoing.

### Daily report

Managers access `GET /api/v1/hotels/{hotel}/occupancy-coverage-report?date=YYYY-MM-DD&filter=all&page=1&per_page=25`. The endpoint is under the existing `hotel.member` and `hotel.admin` middleware. Reception, housekeeping and maintenance have no access to the aggregate report. Each entry is scoped to physical rooms in the selected hotel.

Counts:

- **Room count**: rooms currently in that hotel's room catalogue, including those without assigned inspections
- **Checks assigned / coverage %**: rooms with a Phase 53 verification record for the requested date
- **Missing**: room has *no assigned check* for that date, **not** a vacant room
- **Assigned**: a check is assigned but has no observation
- **Observed**: submitted, verified or flagged independent observations
- **Submitted**: observed but not yet reviewed
- **Verified**: manager-reviewed matching observation
- **Flagged**: manager-marked issue requiring investigation
- **Unexpected occupied**: staff observed occupied while the inspection-time registered PMS check-in snapshot said vacant
- **Expected occupied but vacant observed**: opposite discrepancy
- **Unverified**: staff could not determine occupancy

Date-scoped reports use the observation snapshot saved in Phase 53 and never silently rewrite old findings when bookings change. Room catalogue totals represent **current inventory**, not necessarily inventory as it existed on the historical date; deleted/added rooms can affect historical coverage percentages. That limitation must remain visible.

Room rows can be filtered by `all`, `missing`, `assigned`, `needs_review`, `mismatch`, `flagged`, or `verified`. Pagination is required for larger hotels. No payment fields, guest identity details, staff disciplinary conclusion or personal guest information are included.

### Permissions and operations

- The report is **read-only**; it neither changes room readiness nor books rooms, checks guests in/out, creates cash receipts or resolves observations.
- For observation and independent manager review, staff continue using the existing `/occupancy-verification` workspace.
- Cross-hotel access remains denied by the current active hotel membership.
- Missing checks and mismatches must be followed up under normal hotel privacy procedures. Do not treat the report as occupancy telemetry from physical door activity.

### Deferred modules

The keycard feature (Phase 54) is hidden from PMS routes, navigation and booking shortcuts. Its Laravel tables and code remain preserved; no real card encoding is enabled. There is no active CCTV interface in the PMS. These modules remain deferred until explicitly requested again. Hiding a frontend route is not a backend security boundary; hotel role and token checks remain enforced for existing keycard API endpoints.

### Validation

```bash
php artisan test --filter=OccupancyCoverageReportPhase55Test
php artisan test --filter=OccupancyVerificationPhase53Test
php artisan test
php artisan route:list --path=occupancy-coverage-report
```

Frontend:

```bash
npm test
npm run build
```

CI and production deploy status must be verified on the relevant latest commits. The next non-hardware milestone is Phase 57 — Night Audit and Daily Revenue Reconciliation.
