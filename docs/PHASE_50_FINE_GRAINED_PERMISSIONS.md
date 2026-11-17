# Phase 50 — Fine-Grained Hotel Permissions

## Scope and enforcement

Authorization is **backend-enforced per request**, using the active user's current selected-hotel membership and Sanctum token capabilities. The React workspace is a convenience, never an authorization boundary.

| Operation | Manager (`admin`) | Reception (`front_desk`) | Housekeeping | Maintenance |
| --- | --- | --- | --- | --- |
| Create booking | Yes | Yes | No | No |
| Assign a confirmed booking to a physical room | Yes | Yes | No | No |
| Check in / check out | Yes | Yes | No | No |
| Read reservation/guest/folio details | Yes | Yes | No | No |
| Update *own* explicitly assigned cleaning task | Yes | No | Yes | No |
| Update *own* explicitly assigned maintenance ticket | Yes | No | No | Yes |
| Create/release dated room blocks | Yes | No | No | No |
| Cancel/amend a booking | Yes | No | No | No |
| Record/review payments or request/review refunds | Yes | No | No | No |
| Manage staff, policies, hotel settings, catalogue | Yes | No | No | No |

`admin` is the existing manager/owner role. This phase **does not introduce a distinct `manager` database role** or a new exception-approval queue. Existing manager-only review endpoints remain in place; the existing finance flows require independent reviewers where the service specifies them.

Receptionists need active `front_desk` membership in the selected hotel, `hotel:read` and **`booking:write`** bearer token ability (or trusted first-party Sanctum session); admins retain the established `hotel:write` check. Login now issues `booking:write` for users with at least one active front-desk membership. Log out/in to obtain updated token abilities. Permissions are rechecked against the current hotel role on every request, so disabling/removing a membership takes effect without reissuing a token.

Room assignment retains the transactional locks, collision checks, dated inventory block checks, and `reservation_booking_events` audit. Bookings and check-in/out use the existing transactionally audited domain services. Housekeeping update continues to check linked `assigned_user_id` and expected revision; free-text `assigned_to` does not grant rights. Task completion does not authorize keycards or certify readiness.

Manager approval remains required for cancellation, priced reversals, manual payments, refunds, rate/policy changes, and readiness inspection review. Where finance service code requires a second administrator, approval by the initiating actor remains prohibited. An administrative role alone is **not** proof of independent bank/payment verification or physical occupancy.

## Operational limitations

- Receptionists can book **existing hotel guests**, but creation/editing of guest profiles is still manager-only. Frontend hides the quick-create guest action for receptionists. Guest registration for walk-ins needs a separate audited intake workflow before rollout.
- No universal exception-request or manager-approval queue was added. Sensitive changes outside the explicitly mapped actions continue returning 403 to receptionists.
- Certain other hotel-member read-only endpoints remain broadly accessible; complete a privacy/data-minimization audit before production use. In this phase, guest-directory, reservation-detail, manifest and selected finance detail reads are restricted.
- Role permissions are static policy code, not a customer-configurable permission editor. No UI selection of arbitrary privileges.
- Frontend roles are presentation only. Do not trust the client, an unverified guest identity checkbox, a housekeeping `done` state, a payment attestation, or readiness preview to prove eligibility.

## Validation and rollout

1. Deploy backend before frontend; existing administrator writes continue to use `hotel:write`.
2. Run `php artisan test --filter=FineGrainedPermissionsPhase50Test`, the full backend suite, migrations on MySQL/PostgreSQL and frontend `npm test` / `npm run build`.
3. Reauthenticate receptionists to obtain `booking:write` on their tokens.
4. In isolated staging, verify receptionist booking → audited room assignment → check-in → checkout → checkout-clean task. Check hotel-local date and readiness enforcement policy.
5. Test unauthorized direct API calls, inactive staff, revoked membership, cross-hotel records, missing token capability, and an unassigned housekeeping worker attempting task updates.
6. Before live rollout, verify concurrency races under MySQL/PostgreSQL, physically reconcile the room roster and test with hotel staff. These have not been verified in this connector session.

No changes to production guest data or policies are applied automatically.
