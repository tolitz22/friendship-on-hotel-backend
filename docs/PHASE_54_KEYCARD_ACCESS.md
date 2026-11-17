# Phase 54 — Vendor-neutral Hotel Keycard Authorization (Mock-Only)

## Security boundary

Phase 54 records PMS *authorization decisions*, **not** actual card issuance, credentials, door unlocks, physical revocations or door-access evidence. The only registered provider is `MockKeycardProvider`, implementing `KeycardProvider`. Every API response includes `provider: "mock"` and `physicalCardEncoded: false`. The mock reference is a test identifier, not a card UID or a credential. Never represent it as physical access.

No SDK calls, key material, NFC encoding, door logs, lock-network commands, cloud credentials, or access tokens are implemented. A real provider requires a separate integration milestone, verified lock/hardware capabilities, a vendor SDK contract, physical issuance/revocation acknowledgements, and independent security review.

## Roles and lifecycle

- **Front desk**: active selected-hotel receptionist with `hotel:read` and `booking:write` may request initial or exception authorizations. They can read only their own requests. They cannot approve/reject or revoke.
- **Manager**: active selected-hotel administrator with `hotel:write` may request, review, revoke and view the hotel queue. A manager cannot approve/reject **their own** exception request.
- **Housekeeping and maintenance**: cannot view or request keycard authorizations.

All writes first lock the hotel row, then validate current hotel membership and booking/room state as appropriate. Requests are idempotent with `(hotel_id, idempotency_key)`, and different payloads cannot reuse the same key. Events record actor IDs and timestamps.

### Initial guest authorization

- Requires an actual `checked_in` booking with a room assigned **in the same hotel**.
- The hotel-local date must be within `[check_in, check_out)`.
- The room is taken from the reservation; clients cannot override it.
- Duplicate active initial authorizations for the booking/room are rejected.
- Immediately marks **`authorized_mock`**, not physically issued.

### Replacement authorization

- Requires the same live guest booking/room and an existing non-revoked initial **mock** authorization.
- Starts `pending_manager`; a different manager must independently review.
- The original authorization and booking state are checked again **at manager approval**.
- No duplicate pending replacement for the same room/booking.
- Successful manager review creates an audited `authorized_mock` record; it never encodes a replacement card.

### Emergency access exception

- Requires an existing same-hotel physical room and a documented reason; no guest booking is asserted.
- Starts `pending_manager`; a different manager must approve/reject.
- The request is valid for the **hotel-local request date only** and is revalidated on approval.
- Duplicate pending emergencies for the same room are blocked.
- Approval **does not unlock** the room or issue any credential. Staff must follow independent emergency and guest privacy procedures.

### Review, revoke and audit

- A different manager can approve or reject a pending exception with a written reason.
- Invalid/stale/revoked circumstances prevent approval; rejection is still possible to clear the queue.
- Only an active `authorized_mock` entry may be revoked, by a hotel administrator, with a written reason. **Physical card revocation is not supported**.
- Successful reservation **check-out automatically retires all active mock authorizations** for that booking in the same hotel-locked transaction and writes a per-authorization revocation audit event. A failed checkout rolls back the mock revocations. This does not communicate with physical locks.
- Request/approval/rejection/mock-authorization/revocation events are append-only in the app and have no delete routes; privileged SQL can still alter records without database-level auditing.
- The state reflects mock authorization decisions, not whether the guest has a working physical card or whether a lock accepted credentials.

## API

Under `/api/v1/hotels/{hotel}`:

| Method | Path | Access |
| --- | --- | --- |
| GET | `/keycard-authorizations?status=pending_manager&bookingId=123&page=1` | Manager sees property; receptionist only own |
| GET | `/keycard-authorizations/{authorization}/history` | Manager or original requester |
| POST | `/keycard-authorizations` | Receptionist with booking write, or administrator |
| POST | `/keycard-authorizations/{authorization}/review` | Other administrator, independent review |
| POST | `/keycard-authorizations/{authorization}/revoke` | Administrator only |

Example initial request:

```json
{"idempotencyKey":"retry-stable-unique-key","kind":"initial","bookingId":123,
 "reason":"Guest identity independently confirmed at check-in."}
```

Replacement: `kind: "replacement"` and a checked-in `bookingId`.
Emergency: `kind: "emergency_access"` and `roomId` instead of `bookingId`.

Review:

```json
{"decision":"approve","note":"Confirmed guest instruction and recorded independent review."}
```

Revocation:

```json
{"note":"Mock authorization withdrawn following guest report."}
```

## Deployment checklist

1. Run `php artisan migrate --force` **after reviewing database backup and schema on staging**.
2. Run `php artisan test --filter=KeycardAuthorizationPhase54Test`, `php artisan test` and `php artisan route:list --path=api/v1/hotels`.
3. Run frontend `npm test`, `npm run build` and test property switching, permissions and lost-card flows.
4. Test checkout and room reassignment **between request and approval**, concurrent duplicate requests, inactive staff, multi-manager concurrency on real MySQL/PostgreSQL.
5. Select a certified lock/keycard vendor and inspect **actual** software integration capabilities before enabling physical issuance.
6. Establish loss reporting, card-holder identity verification, emergency consent, retention and immutable database audit controls.

The new migration and tests are committed directly to `main`; CI and actual hardware interoperability must be separately verified before go-live.
