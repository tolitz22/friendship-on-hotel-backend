# Business rules — unresolved

No reservation, financial or inventory mutation should be implemented before requirements approval.

1. Book physical room, room type, or both?
2. When must physical room assignment occur?
3. What room holds, maintenance blocks, overlapping nights, no-show, and overbooking policy applies?
4. What are reservation amendment and cancellation policies?
5. Hotel-local check-in/out times, early/late arrival and night audit?
6. Rates, deposits, taxes, charges, refunds and folios?
7. Who may override restrictions, and what must be audited?
8. Is multi-property support in scope, or only future-ready?

UI sample records do **not** constitute approved policies.

## Phase 2 decision log

All eight questions above remain unresolved. Phase 2 stores optional physical room references and half-open stay dates but **does not select an inventory-selling model**. See [Reservation domain decisions](RESERVATION_DOMAIN.md) for consequences and alternatives. The backend now has structural reservation records with no mutation API and an overlap-analysis utility that does not promise availability.

## Phase 9 decision checkpoint

Read `docs/PHASE_9_RESERVATION_DEMAND.md` before implementing any booking mutation. Phase 9 reports every stored reservation record by hotel night and room type (assigned or unassigned) **without filtering lifecycle status or computing sellable inventory**. The hotel has not approved inventory unit, assignment timing, holds, maintenance blocks, statuses, overbooking, rates, or cancellation rules. No reservation mutation may be enabled from these diagnostic totals.

## Phase 10 owner decision capture

Hotel administrators can now record **draft** selections via `GET/PATCH /api/v1/hotels/{hotel}/reservation-policy-draft` and inspect version history. The default is undecided for every field. A complete draft is still **not approved** (`effectiveForBookings: false`). Additional requirements such as timed-hold duration, cancellation cutoff/fees, capacity/rate engine and idempotent MySQL/PostgreSQL concurrency safeguards remain unresolved. No real booking writes are authorized merely by filling the draft. See `docs/PHASE_10_POLICY_DRAFTS.md`.
