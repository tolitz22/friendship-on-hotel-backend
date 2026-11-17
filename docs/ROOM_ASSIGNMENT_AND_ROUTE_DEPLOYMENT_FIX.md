# Reservation room assignment and missing Laravel routes — deployment repair

## Code changes on main

- `GET /api/v1/hotels/{hotel}/bookings/{booking}/room-choices?page=1&per_page=24` lists matching physical rooms only, returning `status: available|unavailable`, optional reason `maintenance_block|reservation_conflict`, and the current room label. The hotel/booking/date/type scope is validated on the React side.
- This is an **advisory read**. `PATCH /bookings/{booking}/assign-room` remains authoritative and uses the hotel-first transaction lock, active dated-block and reservation-conflict checks. No room is reserved by GET.
- The live reservation detail now includes a readable guest name, room category, and assigned room number for permitted staff, without adding unrelated sensitive guest information.
- `RoomChoicesReservationUxTest` checks reservation conflicts, maintenance blocks, tenant scope and route registration.

## Production route 404 observed

The browser reported:

```text
The route api/v1/hotels/1/approval-requests could not be found.
The route api/v1/hotels/1/operational-exceptions could not be found.
```

**Both GET routes exist in the Laravel `main` source.** These particular 404s therefore indicate the responding server does not have those routes active, for example a stale backend deployment, cached Laravel routes, or frontend pointing to an older backend. Frontend-only changes cannot make a missing Laravel route available.

On the running backend environment (after deploying backend `main`), with backup and deployment permissions:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan route:list --path=api/v1/hotels
```

Verify the route list includes:

- `GET api/v1/hotels/{hotel}/approval-requests`
- `GET api/v1/hotels/{hotel}/operational-exceptions`
- `GET api/v1/hotels/{hotel}/bookings/{booking}/room-choices`

**Do not** disable middleware or create public aliases as a workaround. `VITE_API_URL` on the frontend should point to the correct backend root with `/api/v1` **exactly once**. Restart the backend process after updating the deployment and clearing route cache.

Validation:

```bash
php artisan test --filter=RoomChoicesReservationUxTest
php artisan test --filter=ManagerApprovalPhase51Test
php artisan route:list --path=api/v1/hotels
```

Multi-process concurrency/actual production behavior and deployment success are not verified by source commits.
