# Phase 5 frontend integration — auth and Guests

The React frontend has implemented a development-oriented live staff sign-in and property selection flow. The Laravel backend's existing Sanctum bearer-token API is the authority.

## Live endpoints consumed

- `POST /api/v1/auth/login` — returns staff token (dev browser held in memory only)
- `GET /api/v1/auth/me` — current staff and active hotel memberships
- `POST /api/v1/auth/logout`
- `GET /api/v1/hotels/{hotel}/guests` — read directory with search and pagination
- `POST /api/v1/hotels/{hotel}/guests` — active admin only
- `PATCH /api/v1/hotels/{hotel}/guests/{guest}` — active admin only

Hotel access, token read/write abilities and active account checks continue on the server. React does not dictate permissions.

## Development CORS

`.env.example` and `config/cors.php` include `http://localhost:5173` and `http://127.0.0.1:5173` as development origins. Set `FRONTEND_ORIGINS` explicitly for deployments; do not wildcard real guest PII. After a local env change, run `php artisan config:clear`.

## Security scope

This phase uses a token retained **only in frontend module memory**. Reload requires re-authentication. It is a temporary developer integration approach, **not final browser-session architecture**. Prefer approved Sanctum cookie/CSRF authentication or a secure BFF session for production.

Never log tokens, use `localStorage`, grant guests API access from the browser without authenticated hotel scope, or use demo guest records silently after a live API error. Use HTTPS outside localhost. Guest profile data is personal information; production needs audit, rate limiting, role policy, retention and privacy reviews.

## Mock UI compatibility

Only Guests is live in `VITE_DATA_SOURCE=api`. React explicitly keeps other dashboard, reservation, room, front-desk, billing, housekeeping, maintenance, reports and settings screens in *labeled mock mode* while their backend contracts are incomplete. No reservation writes, availability guarantees or payment actions are provided.

The last all-mock frontend milestone was Hotel Settings & Configuration. See frontend `docs/PHASE_5_LARAVEL_INTEGRATION.md`.

## Tests

Run the existing backend full feature suite on SQLite, MySQL and PostgreSQL through `.github/workflows/backend.yml`. For the frontend run `npm run typecheck`, `npm run test` and `npm run build`. Do not claim a complete end-to-end integration pass without running a browser against a live Laravel database.

## Next

Design production browser sessions, audit trails and rate limits, followed by screen-by-screen live DTO integration. Inventory booking policy questions remain unresolved.
