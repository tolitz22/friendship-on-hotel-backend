# LOG-1 — Structured application logging

Production and staging default to stderr, INFO and newline-delimited JSON when no explicit logging settings override them. Railway can collect stderr without a volume, database migration, queue or new service. Existing explicit settings are respected. The single-file channel also uses the safe formatter in production/staging, but is not collected automatically by Railway. Local/testing single-file logging retains Laravel's original format for development.

## Railway backend variables

Set or verify `APP_ENV=production` (or staging), `APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `LOG_LEVEL=info`. An existing `LOG_CHANNEL=stack` with `LOG_STACK=single` still writes to a file; change to stderr or set `LOG_STACK=stderr`. Never use the log mailer in production. Redeploy after configuration changes and check actual Railway log visibility.

## Schema and privacy

Monolog emits one JSON record per line: message (reviewed event name), level, level_name, channel, datetime, context and extra (service, environment, schema_version). Only specifically allowed numeric identifiers/counters enter context. Exception class and sanitized source filename/line are retained; raw exception messages, SQL values, stack arguments, HTTP bodies, tokens, cookies, guest details and arbitrary context/extra are dropped. Unreviewed messages become pms.application.log or pms.application.exception, so add a reviewed constant event name for new technical events. This deliberately trades raw error text for safer diagnostics. Do not add user input to event names. This does not sanitize PHP/runtime output outside Laravel logging, emergency fallback logs or console output; restrict log access and review those separately. Local plaintext logs are not safe for production. Business audit history stays in its existing database records, with no changes to retention.

## Verification

Run `php artisan test tests/Unit/SafeStructuredLoggingTest.php tests/Feature/StructuredLoggingChannelTest.php`. In the deployed backend run `php artisan pms:logging-check`, then look for pms.logging.smoke in the configured log destination; the command only submits an INFO event and cannot prove delivery or bypass a higher threshold. It does not touch hotel records. If Railway filters CLI stderr separately, run in the service's log-captured execution context.

API request IDs, authentication event coverage, centralized logging, retention and alerting are later phases. LOG-1 does not create new database records, automatically log every HTTP request, or configure Railway variables.
