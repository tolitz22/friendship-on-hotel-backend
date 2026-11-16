# Architecture baseline

- Two repositories: Laravel REST API and React/Vite frontend.
- Modular monolith: introduce modules as the use cases solidify.
- PostgreSQL, future per-property scoping and Sanctum SPA auth.
- Backend authoritative for inventory, transitions, price, payments, reporting.
- Initial read-only diagnostic endpoint only. No operational reservation endpoints.
- Decide inventory accounting and concurrency approach before any booking implementation; create explicit simultaneous-booking integration tests.
