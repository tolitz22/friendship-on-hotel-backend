# LOG-2 — API request tracking and correlation IDs

Every non-OPTIONS API request entering the Laravel global middleware receives a new server-generated UUID v4. The response contains X-Request-ID; client-supplied IDs are ignored. The header is exposed to permitted cross-origin frontend clients. Health and browser preflight traffic is excluded.

The completion event pms.api.request.completed contains request_id, HTTP method, registered route template (or unmatched), HTTP status and elapsed middleware time in milliseconds. No actual route parameter, URL query, headers, request/response body or IP is recorded. Structured logs emitted during the request, including Laravel-reported exceptions, receive the same ID. The attribute is cleared in finally; no shared logger context is retained between requests. Completion logging failures do not change the response. Requests failing before Laravel middleware, process termination and failures while rendering an error response may not yield a completion record/header. Duration excludes network and streamed response transmission.

Normal and expected 4xx responses log at INFO, 429 at WARNING, and 5xx at ERROR. Set LOG_LEVEL=info for full coverage. This deliberately does not introduce database writes, a queue, or additional hosting. Railway retention and central log collection remain separate phases.

## Verification
Run php artisan test tests/Feature/ApiRequestLoggingTest.php tests/Unit/SafeStructuredLoggingTest.php tests/Feature/StructuredLoggingChannelTest.php. After deployment use browser developer tools or curl -i against an API endpoint and search the Railway logs for the returned X-Request-ID. Use a non-sensitive synthetic request. A request ID is a diagnostic identifier, not an authorization credential.
