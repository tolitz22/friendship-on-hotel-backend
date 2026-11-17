# Phase 51 — Manager Approval Workflows and Exception Controls

## Implemented behavior

Two **narrow** exceptions are supported:

- `cancel_booking`: an *unpriced*, confirmed booking may be cancelled.
- `amend_dates`: an *unpriced*, confirmed booking may have its pre-arrival stay dates changed.

Front-desk staff and hotel admins can submit a reasoned request. Requests are hotel-scoped, valid for 48 hours, idempotent by `(hotel_id, idempotencyKey)`, and capture the exact original booking state, dates, room type, and physical assignment. Only a *different* active hotel administrator with `hotel:write` may review. Housekeeping, maintenance, inactive users, cross-hotel members and read-only bearer tokens cannot submit or review requests.

The review endpoint has two decisions:

- **Reject**: requires a written reason; does not modify the booking.
- **Approve**: immediately executes the request via the existing `ReservationBookingService::cancel` or `ReservationAmendmentService::amend` inside the same transaction. If the state or booking policy changed, the operation returns 409 and the approval stays pending.

Successful approvals are represented as `executed`, not a misleading intermediate `approved` state. Immutable `manager_approval_events` record requested/approved/executed or requested/rejected with actor IDs and timestamps. Domain services also continue their own booking activity logs, with the *reviewing manager* as the mutation actor.

The hotel row lock is obtained before request/review mutations, in line with readiness-writer order. Approval row locking, payload snapshot rechecking, domain revalidation and atomic audit writes prevent normal duplicate processing and stale execution. MySQL/PostgreSQL process-level race tests are still a production deployment gate.

## API

Every endpoint is under `/api/v1/hotels/{hotel}` and requires `auth:sanctum`, an active account, and active selected-hotel membership:

| Method | Path | Access |
| --- | --- | --- |
| GET | `/approval-requests?status=pending&bookingId=123&page=1&per_page=20` | Manager sees hotel queue; receptionist sees own requests |
| GET | `/approval-requests/{approval}/history` | Manager or originating requester only |
| POST | `/bookings/{booking}/approval-requests` | Admin with write token, or front desk with booking write token |
| POST | `/approval-requests/{approval}/review` | Hotel admin with `hotel:write` and not the original requester |

Request body:

```json
{
  "idempotencyKey": "client-generated-stable-key",
  "action": "amend_dates",
  "reason": "Guest asked to extend the planned stay.",
  "checkIn": "2026-12-10",
  "checkOut": "2026-12-13"
}
```

Use `action: "cancel_booking"` with no `checkIn` or `checkOut` for cancellations.

Review body:

```json
{"decision": "approve", "note": "Reviewed arrival schedule and guest request."}
```

Rejection must include a nonblank note. The `manager_approval_requests.status` lifecycle is `pending → executed` or `pending → rejected`. Expired pending requests are not executable; a new request is required.

## Boundaries

This phase intentionally **does not** approve or execute priced booking cancellations, payments, refunds, discounts, keycard overrides, inventory-block releases, or readiness-gate bypasses. Those remain behind their original policy/manager authorization. It does not create a new `manager` membership role: the existing `admin` role is the manager.

A successful request is not guest identity verification, payment settlement, or proof of actual room occupancy. The React UI must not equate approval with authorization to waive financial or readiness rules.

Phase 51 also repairs the Phase 50 receptionist permission matrix so the already-present front desk reservation, room assignment, guest and folio read endpoints work under an active `front_desk` membership.

## Rollout and verification

1. Apply `php artisan migrate` on a backup-tested staging database, then deploy the API before enabling frontend approval submissions.
2. Run `php artisan test --filter=ManagerApprovalPhase51Test` and `php artisan test`, `php artisan route:list`, plus PHP lint.
3. Verify migrations and transaction concurrency on production-target **MySQL 8.4 and PostgreSQL 16+**, not just SQLite.
4. Test two managers reviewing one request concurrently and an admin direct cancellation racing an approval.
5. Run frontend `npm test`, `npm run build` and a real browser flow: receptionist request → manager review → reservation history and request audit.
6. Verify a different hotel, own-request denial, membership changes, expired requests, duplicate keys, priced reservations and financial restrictions.

This code was authored on `main`; passing tests, a production-safe lock benchmark, and deployment were **not verified** by the code-authoring session.
