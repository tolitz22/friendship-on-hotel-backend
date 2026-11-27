# LOG-4 — Centralized business audit feed

Read-only admin endpoint GET /api/v1/hotels/{hotel}/business-audit, using the same hotel member/admin authorization as operational exceptions. Reads 21 source projections across bookings, housekeeping, maintenance, staff, catalog, payments, cashier shifts, expenses and finance. No new table, audit writes, replication or retention changes. Only identifiers, action codes and recorded timestamps are returned; detailed notes, guest/payment data and state snapshots stay in their original authorized workflows. The timeline is neutral activity, not a fraud signal.

Filters: category, days (1–90, default 30), per_page (1–50, default 25), opaque nextCursor. Newest first by timestamp, source rank and ID. Use the same filters on subsequent pages. This is a rolling recent-activity view, not a complete statutory audit export or transactional snapshot. Some domains have only recorded creation/review/correction events rather than exhaustive change history; payment evidence review without a dedicated timestamp is not inferred. A journal and its originating operation may appear separately as distinct records.

Run php artisan test tests/Feature/BusinessAuditTest.php. Existing source-specific audit and finance history remain authoritative. No migrations are necessary; evaluate query plans and source date indexes on larger hotels before scaling.
