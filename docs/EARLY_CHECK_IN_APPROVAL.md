# Early check-in: independent manager approval and auditable actual arrival

## Policy and lifecycle

This workflow is a narrow exception to the default no-early-arrival check-in policy. The safe initial scope is **one hotel-local calendar day before the booked arrival date**. Other dates are deliberately rejected rather than implicitly extending paid room access.

1. Reception or a manager requests `action: "early_check_in"` through the existing `POST /api/v1/hotels/{hotel}/bookings/{booking}/approval-requests` endpoint with an idempotency key and documented reason.
2. The request is accepted only for a *confirmed* booking, with an assigned physical room in the selected hotel, and only when the original arrival date is tomorrow in that hotel's timezone. A duplicate pending request is rejected.
3. The booking remains unchanged and `pending` while awaiting another hotel manager.
4. A different active hotel administrator independently reviews it in the existing approvals inbox. Approval requires a manager note of at least 10 characters and an explicit frontend review attestation; the backend rechecks the booking snapshot, requester's active role, date, assigned room, readiness policy, maintenance blocks, and competing reservations **including the added early night**.
5. **Approving executes check-in immediately** in a single hotel-locked transaction; it does not merely issue a reusable override token. The operational `reservation_records.check_in` is advanced to the actual hotel-local arrival date, so room availability, booking calendars and later assignment conflict queries include the extra occupied night.
6. A dedicated `reservation_early_check_ins` row permanently records the *original booked check-in/check-out*, actual date and timestamp, room, staff who requested, manager who approved/performed check-in, approval request ID, reason, and manager note. The normal reservation state becomes `checked_in`. Its booking event is explicitly `early_check_in`; approval events are requested, approved, executed.
7. Any failure rolls back the approval, operational date change, actual arrival record and all events. Replays and concurrent reviews are rejected. Requests reviewed on or after the scheduled arrival date are rejected as stale, not silently treated as ordinary check-in.

Normal direct `POST /bookings/{booking}/check-in` cannot bypass the date restriction, even with a client-supplied approval ID. There is no public API to choose or replay early approval identifiers.

## Financial boundaries

Early occupancy adds an extra operational night, but **this flow does not automatically increase the folio, change the rate plan, charge a card, waive fees, or issue a refund.** The approving manager must independently review pricing, tax, refund and guest-consent implications and use the existing accounting workflows for any additional-night charge. Booked dates are preserved in the dedicated audit record; they are not represented as unchanged operational inventory dates. Existing rate quotes/folios may need explicit reconciliation.

If the room is not approved as ready under the property's enforced readiness policy or is assigned/blocked for the prior night, approval fails (409) with no check-in or record mutation.

## Read and audit

`GET /api/v1/hotels/{hotel}/reservation-records/{booking}` includes nullable `earlyCheckIn` with scheduled/actual dates, recorded timestamp, approval, requester, approver, reason and note. The paginated reservation list includes the same indicator for staff who are permitted to read booking records. The manager inbox shows the original request and its independent review history.

Audit metadata is append-only through the application. Privileged database changes are not independently tamper-proof; secure DBA privileges, database backups and monitoring remain necessary.

## Validation

```bash
php artisan migrate --force
php artisan test --filter=EarlyCheckInManagerApprovalTest
php artisan test --filter=ManagerApprovalPhase51Test
php artisan test
php artisan route:list --path=api/v1/hotels
```

Frontend:

```bash
npm ci
npm test
npm run build
```

Test one-day-early request, no room, different manager, self-approval, stale date, prior-night conflict, readiness policy, changed room, concurrent requests/reviews, cross-hotel isolation, direct forged override, a priced booking requiring separate accounting review and UI visibility after approval. Run database-specific tests in SQLite/MySQL/PostgreSQL CI before promotion.
