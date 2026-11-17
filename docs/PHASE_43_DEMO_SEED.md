# Phase 43 synthetic hotel fixture seed

This seeder is **opt-in**. It is not called by DatabaseSeeder or normal Railway deploys and never creates a user or password.

## Preconditions

- The Railway backend's **Laravel** \`APP_ENV\` must be \`staging\`.
- Verify the linked PostgreSQL database is disposable, isolated from any real hotel, users, bookings, payment records, or production databases.
- Create an empty test hotel (if necessary): \`php artisan hotel:create "Friendship On Hotel Phase43 Test"\`.
- Give a dedicated new test user admin access: \`php artisan hotel:bootstrap-admin <hotel-id> <test-admin-email>\`.
- The test hotel must have no unrelated rooms, room types, guests or bookings.
- Never use credentials previously pasted into a chat. Rotate and provide credentials directly in the protected GitHub environment.

## Seed using Railway SSH

Use \`railway link\` and \`railway ssh\` from a CLI linked to the correct Railway backend service and staging environment.

Inside the container, set the temporary acknowledgments and replace **N** with the numeric ID of the empty synthetic hotel:

\`\`\`bash
PMS_DEMO_HOTEL_ID=N \
PMS_DEMO_SEED_ACK=isolated-synthetic-staging-only \
PMS_STAGING_ISOLATED_DB_ACK=isolated-test-db \
php artisan db:seed --class=Phase43DemoSeeder --force
\`\`\`

The command prints the **actual** IDs needed by the frontend Phase 43 workflow:

| Seeder output | GitHub staging-write variable |
| --- | --- |
| hotel_id | PMS_STAGING_TEST_HOTEL_ID |
| hotel_name | PMS_STAGING_TEST_HOTEL_NAME |
| room_type_id | PMS_STAGING_TEST_ROOM_TYPE_ID |
| room_id | PMS_STAGING_TEST_ROOM_ID |
| rate_plan_id | PMS_STAGING_TEST_RATE_PLAN_ID |
| checkout_booking_id | PMS_STAGING_CHECKOUT_BOOKING_ID |

The seed creates 2 room types, 3 physical rooms, 2 PHP nightly rate plans, 1 fictional \`example.test\` guest and 1 explicitly synthetic due-today checked-in checkout fixture. The room reserved for checkout (\`P43-199\`) is separate from the new-booking test room (\`P43-101\`).

The arrival-day booking and cash evidence are created **by Playwright** via the real Laravel API, not prefilled by the seeder.

The checkout fixture uses a direct synthetic \`checked_in\` state and audit row solely to test checkout. It is **not** evidence of a real arrival, payment, guest identity verification or inspected room readiness.

The seeder is idempotent for the same test hotel on the same day **before Playwright writes**. After the tests create records or check out the fixture, do not rerun against that hotel; provision a new empty synthetic hotel and fixtures instead. The seeder never deletes data and refuses any hotel with unrelated records.

## Safety

- All seed writes are limited to the provided hotel ID, and the command requires two distinct explicit environment acknowledgments plus \`APP_ENV=staging\`.
- Do not change \`APP_ENV\` on your production service merely to bypass the guard.
- Do not seed against your operational hotel or any database holding real guest information.
- Railway's \`production.up.railway.app\` domain segment is not evidence of isolation.
- For Phase 43 acceptance, use the instructions in the frontend \`docs/PHASE_43_STAGING_BROWSER_ACCEPTANCE.md\`.
