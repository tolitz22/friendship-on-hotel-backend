# Phase 58 — Night Audit: manager review snapshot (NOT financial close)

This phase extends the Phase 57 preflight into an auditable manager review of **completed prior hotel-local dates**.

## Capability
- The manager-only GET/POST `/api/v1/hotels/{hotel}/night-audit/review` endpoints show or record one immutable review per business date.
- Under a hotel row transaction lock, the backend re-runs the preflight, stores the current read-only result as canonical JSON with SHA-256 checksum, timestamps and manager actor.
- A manager can mark **reviewed** only when the preflight reports no listed exceptions. If it reports exceptions, the only permitted action is **flagged for investigation**. The review cannot be silently overwritten, and a duplicate returns HTTP 409. This is **not** a financial attestation of settlement or complete accounting.
- Requires an explicit checkbox acknowledgment plus a manager note of at least 15 characters. Frontend shows the recorded decision, manager, note, time and checksum.
- DB unique constraint `(hotel_id,business_date)` protects concurrent duplicate submissions. The stored review has a separate append-only creation event.
- This does not create, edit or duplicate any booked nightly folio entries. No guest or bank data is exposed to other hotels or non-manager staff.

## Explicitly not included
The reviewed snapshot is **not a financial close**: there is no business-date roll; no historical transaction lock; no taxes/VAT calculation; no verified bank or gateway settlement; no audited accounting debit/credit equality or revenue-recognition journal; no ADR/RevPAR financial approval.

Do not market a green preflight or a completed review as "closed", "balanced", "bank verified" or "ready to file taxes." Its historic data is computed using current reservation and staff-finance records, so it may shift after review. SHA-256 is tamper-evident only if the recorded hash is independently protected; users with database write access can change both.

## Rollout and verification
```bash
php artisan migrate --force
php artisan test --filter=NightAuditReviewPhase58Test
php artisan test --filter=NightAuditPreviewPhase57Test
php artisan test
```
Frontend: `npm test`, `npm run build`.

## Next financial-close milestone design requirements
- Define immutable hotel business date and strictly audit every financial write path.
- Treat preposted room-night entries as *future obligations* until service nights are earned. Do not debit the folio twice.
- Implement approved tax rules and jurisdictional VAT handling, post separately balanced journal entries with idempotency.
- Period-close state machine with an independent reviewer, red-flag conditions, reversible adjustments in a later open period, and strict locks across all ledger mutations.
- Separate cash proof, gateway settlement and revenue recognition. Derive ADR/RevPAR from approved, time-based accounting snapshots.
