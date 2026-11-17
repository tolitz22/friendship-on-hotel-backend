# Must-Have PMS Features — Implementation Track

Updated November 2026. Both main branches must pass CI and have green Railway deployments before a milestone is considered deployed.

## Phase 57: Night audit preflight — IMPLEMENTED (read only)

- GET `/api/v1/hotels/{hotel}/night-audit/preview?date=YYYY-MM-DD` is manager-only, date validated against hotel local date and hotel-scoped.
- Sums only preexisting `folio_entries` room-night rows for selected hotel/night. Booking creation already posts nightly charges; **never post duplicates in a reporting run**.
- Flags missing/duplicated room-night rows and unpriced reservations. Shows unresolved payment evidence and open / submitted / flagged cashier shifts.
- Shows limitations: occupancy is schedule-derived rather than historical physical occupancy; shift counts are current property-wide; taxes, gateway settlement, final payable balance, and day close are **not** validated.
- Frontend manager workspace is `/night-audit`. This does not roll business dates or lock posted entries.

**Required next backend milestone:** a proper business date state machine with atomic day roll, append-only posting journal, per-date and per-folio reconciliation, immutable locked periods enforced at every financial write boundary, amount/currency/VAT policy, reversal rather than mutation, user attestation, owner/reviewer segregation and idempotent retries across PostgreSQL/MySQL. Explicitly design how preposted nightly charges become earned revenue without a second debit. Only then enable day close.

## CRM: Guest stay history — IMPLEMENTED

- GET `/api/v1/hotels/{hotel}/guests/{guest}/stays` is guest.read protected, hotel- and guest-scoped, newest first and paginated.
- Guest directory **View stays** displays history with reference, dates, status and room type/number, with a link to the authoritative reservation detail.
- No lifetime spend is inferred from an unverified folio or staff-confirmed payment evidence.

## Other required core PMS features — NOT completed by this change

**Reservations:** drag-to-move and resize existing rack reservations; atomic multi-room/group/block allocation; deposits and forfeiture, automated policy cancellation, special-request tagging; calendar-based seasonal/corporate/promotional rates.

**Front desk:** ID document workflow with access controls and retention; guest registration signature; fast settlement checkout; split folios; VAT and municipal tax rules; preauthorization/capture/void; walk-in wizard; mid-stay transfer with atomic inventory and ledger adjustment.

**Housekeeping:** live push delivery, field testing of mobile housekeeping workflow, policy-driven ready/inspect/no-override states and OOO/OOS reason codes.

**POS:** typed service/item catalog, incidentals tied to guest folios and linked sources (spa, laundry, restaurant/minibar); live Philippine gateway integrations with verified settlement and accounting evidence.

**Night audit:** business-date roll, per-night revenue recognition, period locks, append-only reversals, fully balanced ledger, ADR/RevPAR daily snapshots. Current reports UI is mock/sample and must not be used for finance.

**CRM and data protection:** verified preferences/VIP/consent fields; sensitive accommodation needs with restricted access; verified stay financial aggregates; breach/retention/export/deletion workflows; Philippine Data Privacy Act and GDPR review when applicable.

**Hardware remains deferred:** real keycard encoding and CCTV by user instruction.

## Verification

```bash
php artisan test --filter=NightAuditPreviewPhase57Test
php artisan test --filter=GuestStayHistoryTest
php artisan test
```

Frontend:

```bash
npm test
npm run build
```

Any production rollout requires QA with real hotel staff and accountant review before automated financial posting is enabled.
