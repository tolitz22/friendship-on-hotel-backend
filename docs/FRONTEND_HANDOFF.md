# Frontend handoff — point of continuity

Source: `tolitz22/friendship-on-hotel-frontend` (separate Git repository).

## Last completed frontend milestone before Backend Phase 1
**Hotel Settings & Configuration**. It followed Reports & Analytics, Maintenance, Housekeeping, Front Desk, reservation calendar, physical-room timeline, and mock reservation-creation wizard.

Current frontend stack: React + Vite + TypeScript + Tailwind CSS 4, TanStack Router and TanStack Query. Both light and dark modes. `VITE_DATA_SOURCE=mock` is the functional development mode. `VITE_DATA_SOURCE=api` switches to HTTP but existing Laravel API endpoints do NOT yet satisfy all contracts.

Current frontend screens:
1. Dashboard — illustrative KPIs, reservations and room conditions
2. Reservations — day/week calendar, filters, list, read-only detail and mock booking wizard
3. Room Inventory — timeline and grid; no availability guarantees
4. Front Desk — mock arrivals/departures, check-in/check-out walkthroughs
5. Guests — read-only directory
6. Billing — sample folios only
7. Housekeeping — task board with local-only draft edits
8. Maintenance — work orders with local-only draft edits
9. Reports — illustrative analytics and CSV export
10. Settings — property information preview, operational times, floor/room counts and example staff roles

**Preserve this work.** No frontend feature changes are part of Backend Phase 1.

## Backend Phase 1 newly implemented
Laravel 12 application scaffold, user/Hotel/RoomType/Room and HotelMembership models, Sanctum token auth, active user and property membership checks, property-scoped reads, and narrow admin-only contact and room-catalog setup writes.

## Critical contract gap (deliberately not auto-connected)
Backend uses property-scoped API paths `/api/v1/hotels/{hotel}/rooms`, `.../room-types`, `.../hotels/{hotel}`. Frontend currently expects `/api/v1/rooms`, `/settings/overview` and operational fields that the backend cannot yet source (occupancy/housekeeping/maintenance/rates). Don't pretend these APIs are interchangeable. Reconcile contract and add authenticated hotel context in an explicit **frontend integration milestone** after backend policies, auth strategy, and domain data are implemented.

## Next steps after backend is runnable
- Verify Laravel CI, migrations, login and per-hotel authorization.
- Confirm whether the hotel sells by room type, specific room, or both; room assignment timing, holds, maintenance blocks and overbooking policy.
- Define check-in/out, housekeeping status, folio and reservation lifecycles.
- Build real operational endpoints with concurrency tests.
- Only then replace mock adapters screen-by-screen with actual Laravel responses and integration tests.

The logo is still represented as a fallback brand mark in the frontend GitHub checkout unless the original uploaded image is explicitly copied into `public/logo.png`.

## Backend Phase 2 update (2026-11-17)

Added backend reservation-record schema, overlap helper, date-only tests, and a property-scoped read-only reservation-record endpoint. No frontend files or screens have been replaced or connected to these records. **The frontend remains paused after Settings, on mock mode.** The new backend endpoint is not equivalent to the frontend's `/reservations` or `/reservations/calendar` contract. Resume frontend integration only after hotel-scoped authentication, operational DTOs and approved booking inventory policies are ready. See `docs/RESERVATION_DOMAIN.md`.

## Backend Phase 3 continuity

A new property-scoped diagnostic endpoint reports structural reservation overlaps for an assigned physical room. This endpoint **is not** a room-availability API and does not match the frontend Room Timeline or booking wizard contracts. No frontend UI changes were made; keep the React/Vite frontend at the completed Hotel Settings milestone and preserve mock mode until approved inventory business rules and authentication DTOs are available. See `docs/PHASE_3.md`.

## Backend Phase 4 — Guest Management

Backend has a property-scoped guest-directory API: `GET /api/v1/hotels/{hotel}/guests`, `GET /guests/{guest}`, plus admin-only `POST` and `PATCH`. The DTO fields match the frontend's typed `Guest` shape, but the frontend's HTTP adapter still uses unscoped `GET /guests`. **No frontend screen or mock adapter has been changed**, and the last completed frontend milestone remains **Hotel Settings & Configuration**. Do not turn on API mode until selected-hotel identity and secure browser authentication are implemented. See `docs/PHASE_4_GUESTS.md`.

## Frontend Phase 5 implemented

React now has staff sign-in against `/auth/login`, in-memory bearer token, `/auth/me` property membership selection, sign-out, and a live Guests directory for the selected hotel. Guest create/edit is admin-only. Other PMS modules remain expressly mock previews. UI work continues from the existing Hotel Settings milestone, not from a rebuild. Frontend docs: `docs/PHASE_5_LARAVEL_INTEGRATION.md`.

## Phase 6 frontend connected read-only catalogue

Frontend API mode now reads real selected-hotel settings, registered room/type counts, floor groups and paginated filtered rooms from Laravel, alongside live Guests. Overview no longer labels sample occupancy figures as live. Reservation calendar/timeline and remaining PMS operational workflows stay mock previews. See both repositories' `docs/PHASE_6_LIVE_CATALOG.md`.

## Phase 7 frontend admin creation

The React frontend can now create room types and physical rooms for the selected hotel via the existing Laravel administrator-only POST APIs. Room counts and listings invalidate after successful creation. Existing read-only views and mock-mode screens remain available. No room catalogue edit/delete, booking writes or availability state is implied. See `docs/PHASE_7_CATALOG_ADMIN.md`.

## Phase 8 — Frontend audited catalogue editing

React now allows hotel administrators to update existing room/type catalogue metadata through new Laravel PATCH endpoints and inspect per-record audit history. Successful edits invalidate room and room type caches. See `docs/PHASE_8_CATALOG_EDITS.md`; the frontend mock modules and business-rule restrictions remain intact.

## Phase 9 diagnostic continuity

React API mode now offers a read-only Demand diagnostic screen, backed by `/api/v1/hotels/{hotel}/reservation-demand`. It includes per-night unassigned/assigned provisional records with prominent non-availability warnings. The existing Reservations calendar, quote and booking wizard remain mock previews. Neither backend nor frontend enables reservation mutation. See `docs/PHASE_9_RESERVATION_DEMAND.md`.

## Phase 10 — Booking policy draft planning

React API mode now exposes the hotel policy draft editor and revision history via `/reservation-policy-draft` endpoints. Drafts store explicit preliminary choices only, with no approval or activation. The backend still has no reservation create/update API. See `docs/PHASE_10_POLICY_DRAFTS.md`.

## Phase 11 — Advisory readiness

The live Booking policies page now consumes the backend's scoped GET `/reservation-policy-readiness`, which returns missing/conditional issues and explicitly denies booking readiness even for complete drafts. No real Reservations wizard integration or booking mutations are active. See `docs/PHASE_11_POLICY_READINESS.md`.

## Phase 12 — Stay simulator

React API mode adds the read-only Stay simulator based on `GET /api/v1/hotels/{hotel}/reservation-simulation` with room ID and inclusive/exclusive date range. It presents assigned-room overlaps, unassigned type demand and other assigned records without claiming availability or activating booking writes. See `docs/PHASE_12_RESERVATION_SIMULATION.md`.

## Phase 13 live maintenance

React API mode now consumes `maintenance-work-orders` (scoped list, detail, history, creation and update). Staff read, admins write. Room impact remains an informational flag and cannot block bookable inventory or alter reservations. See `docs/PHASE_13_LIVE_MAINTENANCE.md`.

## Phase 14 — Housekeeping live integration

React API mode now uses `housekeeping-tasks` list/detail/history and admin create/update routes. Task assignment and completion are saved; room readiness and availability remain separate, unresolved policies. See `docs/PHASE_14_LIVE_HOUSEKEEPING.md`.

## Phase 15 — Hotel staff and assigned workers

The API-mode frontend now offers `Hotel staff` to administrators and linked `assignedUserId` selectors on live housekeeping/maintenance forms. Active assigned role members may PATCH only the status and note of their own linked task; the API retains server-side authorization. Legacy assignment strings grant no rights. See `docs/PHASE_15_STAFF_PERMISSIONS.md`.
