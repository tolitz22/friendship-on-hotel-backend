# LOG-3 — Authentication and security logging

Security events go through the existing LOG-1 structured channels and LOG-2 request correlation. No new tables, credentials, network destinations or login policy changes.

Events: bearer and cookie login success/credential rejection and logout; password reset request (without account identity), broker reset success/failure, password change success/incorrect current password; HTTP 401/403/419/429; explicit hotel membership denial (404); actual staff account creation, attachment and access updates after the transaction returns; invitation sent, revoked and accepted. Existing hotel_staff_events remains the authoritative staff audit record. Failed credential attempts do not disclose account existence in logs. No email, IP, password, reset/invite token, permission payload, or raw request data is logged. Known authenticated actor/target IDs are included where explicitly available. HTTP status events are generic and correlated to the route template in LOG-2, not proof of an attack. Invalid-form submissions may produce only the API completion event; CLI and failures before middleware are not covered. Repeated-failure alerts, aggregation and retention are later phases.

INFO enables success events; WARNING enables denied/failed events. Logging failures are best-effort and must not block authentication. Restrict Railway logs to platform operators, never expose cross-hotel records to property staff.

Tests: php artisan test tests/Feature/SecurityLoggingTest.php tests/Feature/AuthenticationTest.php tests/Feature/SpaSessionAuthenticationTest.php tests/Feature/StaffPasswordSecurityTest.php tests/Feature/HotelStaffPermissionsTest.php tests/Feature/ApiRequestLoggingTest.php. Use synthetic accounts for live verification.
