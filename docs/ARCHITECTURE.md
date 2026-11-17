# Architecture baseline

- Two repositories: Laravel REST API and React/Vite frontend.
- Modular monolith: introduce modules as the use cases solidify.
- PostgreSQL, future per-property scoping and Sanctum SPA auth.
- Backend authoritative for inventory, transitions, price, payments, reporting.
- Initial hotel/room catalog APIs and Phase 2 read-only structural reservation-record endpoint; no reservation mutation or live availability endpoint.
- Decide inventory accounting and concurrency approach before any booking implementation; create explicit simultaneous-booking integration tests.

- Phase 2 maintains the same database hotel scope for reservation rows and optional room assignments, and enforces checkout-exclusive hotel-local stay dates. See [RESERVATION_DOMAIN.md](RESERVATION_DOMAIN.md).
