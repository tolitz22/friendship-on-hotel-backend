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
