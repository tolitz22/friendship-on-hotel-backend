# Backend Phase 4 — Guest Management Foundation

Status: implementation published; runtime test suite must pass before use with real guests.

## Database
The new `guests` table has:
- `id` — primary key
- `hotel_id` — required parent property foreign key
- `first_name`, `last_name` — required, max 120 characters each
- `email` — optional, max 255 characters
- `phone` — optional, max 40 characters
- `nationality` — optional, max 100 characters
- created/updated timestamps, plus a hotel/name index

Records are specific to **one hotel**; guest identities are not automatically deduplicated or shared across properties. Email addresses are not unique because family bookings, reused emails, and incomplete profiles need a verified business policy before enforcing uniqueness.

No passport numbers, dates of birth, identification scans, addresses, payment credentials, health information, or sensitive guest notes are collected by this milestone.

## Endpoints

All endpoints require an authenticated, **active staff account with an active membership in that hotel and a `hotel:read` token ability**:

| Method | Route | Access |
| --- | --- | --- |
| GET | `/api/v1/hotels/{hotel}/guests` | Active hotel member |
| GET | `/api/v1/hotels/{hotel}/guests/{guest}` | Active hotel member |
| POST | `/api/v1/hotels/{hotel}/guests` | Active hotel admin + `hotel:write` |
| PATCH | `/api/v1/hotels/{hotel}/guests/{guest}` | Active hotel admin + `hotel:write` |

**No DELETE endpoint is implemented.** Hotel-specific record retention/erasure, audit trails, and guest merging still need approval.

This conservative Phase 4 policy restricts writes to administrators. A future approved Front Desk guest-editing permission can be introduced explicitly with server-side policy checks; UI labels alone do not grant it.

The guest lookup and update operations use `$hotel->guests()`, never a global guest lookup. An ID from another hotel returns 404.

## List/search

```http
GET /api/v1/hotels/1/guests?search=juan&page=1&per_page=20
```

Pagination defaults to 20 and has a hard maximum of 100. The search applies to first/last names, email and phone, with literal wildcard escaping and case-insensitive matching. Results sort by last name, first name, then ID.

## Response DTO

```json
{
  "data": {
    "id": "12",
    "hotelId": "1",
    "firstName": "Juan",
    "lastName": "Santos",
    "email": "juan@example.test",
    "phone": null,
    "nationality": "Filipino",
    "createdAt": "2026-11-17T11:55:00+08:00"
  }
}
```

`GET /guests` returns `data: [Guest]` and `meta: {page, perPage, total, totalPages}`. `POST` returns 201, `PATCH` returns 200.

`POST` requires `firstName` and `lastName`; `PATCH` permits partial updates. Name values cannot be empty/whitespace-only. Optional contact fields accept explicit `null` to clear them. Invalid fields return 422.

## MySQL/PostgreSQL portability

The migration uses only Laravel's portable schema builder, varchar columns, timestamps, an indexed hotel/name path and an ordinary FK. The controller uses Eloquent and parameterized query conditions; no vendor-specific SQL is required. Fast SQLite tests and full MySQL/PostgreSQL CI jobs cover the guest feature as part of the existing test suite.

## Privacy and production readiness

Guest profiles are **personal data**. Before using this on a real hotel:
- Approve staff least-privilege roles and per-action authorization.
- Establish record retention, access logging, correction and erasure policies consistent with applicable law.
- Use TLS, authenticated sessions, secure backup access and database encryption controls.
- Review contact search behavior and rate limits, especially to prevent bulk extraction.
- Add a hotel-approved audit trail for all profile edits.
- Do not store passports, photos or payment card details in this module.

This milestone is a developer foundation, **not** a certified privacy-compliant guest registry.

## Frontend continuity

Existing frontend `src/api/types.ts` already defines `Guest` with exactly these DTO property names. However, frontend HTTP mode currently calls `GET /guests`, while backend requires hotel-scoped `GET /hotels/{hotel}/guests` and an authenticated staff context. No frontend code was changed. Keep `VITE_DATA_SOURCE=mock` until authentication and selected-property routing are integrated.

## Test commands

```powershell
git pull origin main
php artisan migrate
php artisan test
php artisan route:list --path=api
```

Tests: `tests/Feature/GuestManagementTest.php` (permissions, isolation, pagination, search, input validation, partial updates, no deletion).

## Next milestone recommendation

Implement secure selected-hotel context and authentication integration in the frontend **after reviewing the session/cookie strategy**. Continue real reservation/inventory mutation design only when booking policy questions have been approved.
