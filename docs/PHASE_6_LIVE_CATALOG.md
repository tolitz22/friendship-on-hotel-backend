# Backend Phase 6 — Verified Property Catalogue Read Model

## Scope

This phase supports read-only Laravel/MySQL/PostgreSQL integration for Overview, Room Inventory and Hotel Settings in the separate React/Vite frontend.

New endpoint: `GET /api/v1/hotels/{hotel}/catalog-summary`. Requires Sanctum active user, `hotel:read` and active hotel membership through existing middleware.

Response `data` contains:
- `hotelId` (string)
- `totalRooms` (count of registered physical room rows)
- `totalRoomTypes` (registered room type rows)
- `floors` (nullable numeric floor and roomCount grouped within selected hotel)
- `roomTypes` (id, name, maxOccupancy and roomCount within hotel)

Response `meta` contains `isAvailability:false`, `isOccupancy:false`, and `scope:physical_room_catalog`.

These are **catalogue counts only**, not sellable capacity, physical occupancy, readiness, inventory blocks or guest activity. A room exists in the catalogue even when unavailable; do not derive occupancy/vacancy from its presence.

## Enhanced read endpoint

`GET /api/v1/hotels/{hotel}/rooms?floor=2&search=201&page=1&per_page=20`.

- `floor` optional nonnegative integer (up to 65535)
- `search` optional text up to 120 characters, case-insensitive and literal wildcard escaping, applied to room number
- `page` positive integer, `per_page` integer 1–100
- Invalid filters return 422; queries remain property-scoped

The existing room API DTO remains unchanged (id, hotelId, room number, nullable floor, type ID/name, max occupancy). No occupancy, housekeeping or maintenance data has been added.

Hotel property and room types are read with existing endpoints. No new migration is required for Phase 6.

## Portability, performance and privacy

Uses Eloquent `withCount()`, scoped table count/group queries and portable SQL through Laravel for both MySQL/InnoDB and PostgreSQL. Avoid database-specific range operators. For very large inventories, profile the catalogue aggregate and add caching only with correct invalidation on room and type writes.

Tests: `tests/Feature/LiveCatalogTest.php` covers tenant isolation, counts/groups, no false occupancy claims, filtering, wildcard safety and unauthorized access.

## Frontend contract

Frontend `docs/PHASE_6_LIVE_CATALOG.md` describes the mapped UI. Overview, Rooms, Settings and Guests are genuinely sourced from the selected property in API mode. The old mock pages are retained only for the explicit mock configuration. Other modules still show `MOCK PREVIEW`.

Do not enable live reservation creation or occupancy calculations until hotel inventory, holds, maintenance blocks and lifecycle policies are approved.
