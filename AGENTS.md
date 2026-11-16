# Friendship On Hotel — Backend Codex Instructions

Backend repository for Laravel API. Paired frontend: `friendship-on-hotel-frontend`.

Read `docs/BUSINESS_RULES.md` and `docs/ARCHITECTURE.md` before changes.
- PHP 8.3+, Laravel, PostgreSQL, Sanctum SPA authentication when implemented.
- Use policies, Form Requests, thin controllers, application actions and transactions.
- Never invent booking, cancellation, billing or tax policies.
- Test simultaneous reservations using database-level consistency.
- Distinguish physical occupancy, inventory, maintenance and housekeeping.
- API must be authoritative for room availability, pricing, payments and access.
- No secrets in Git. Add tests, migration constraints, and docs with each feature.
- Current code is an initial scaffold, not a complete Laravel installation.