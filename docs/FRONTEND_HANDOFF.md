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
