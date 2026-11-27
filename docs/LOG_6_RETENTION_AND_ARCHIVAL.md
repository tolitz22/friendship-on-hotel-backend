# LOG-6 — Retention and archival

## Railway (recommended)
Keep LOG_CHANNEL=stderr, LOG_LEVEL=info and APP_DEBUG=false. Laravel does not control the provider's stderr retention, export, or storage limits. No volume or database writes are needed. Inspect the actual Railway plan and log settings before promising any retention duration. LOG-7 will address external collection; configure retention at that destination. This phase does not change Railway settings.

## Policy targets (not claims of currently enforced provider retention)
- Debug: 7 days, only when explicitly enabled for diagnosis.
- Routine API requests: 14–30 days.
- Technical errors: 90 days.
- Security events: 180 days, access restricted to operators.
- Operational business audit: review against hotel contracts, privacy requirements and legal obligations.
- Financial journals, payments, settlement and accounting history: never subject to technical cleanup; retain under applicable accounting/tax obligations and any legal holds.

## Optional local file mode
LOG_CHANNEL=pms_daily and PMS_LOG_FILE_DAYS=14 activate a separate, sanitized daily channel with 0600 file permissions. Retention is a maximum number of dated files (1–90), enforced by Monolog on rotation, not a precise calendar expiry or byte cap. Do not use single-file mode in production; it does not rotate. The default stderr path remains unchanged. Do not combine channels unless duplicate output is explicitly wanted.

Run php artisan pms:logs-retention-check for read-only configuration verification. A warning about legacy single-file logging does not delete or migrate it.

Run php artisan pms:logs-archive for a dry-run preview. Add --write only when a private local archive is needed. The command copies up to ten eligible completed pms-technical-YYYY-MM-DD.log files (8 MiB per source) to storage/app/private/pms-log-archives/*.gz, never the current day's file, symlinks, legacy laravel.log or business data. Maximum 30 archive files / 128 MiB; operator must export/manage copies when full. No overwrite or source deletion; no scheduled archival by default. Archive before rotation if historical copies are required. Local archives are not backups on ephemeral hosting and can disappear on redeployment. Use access-controlled durable storage and encryption at rest for exported logs; do not publish them. This deliberately does not implement remote upload, automatic archive deletion, or any business-record pruning.

Tests: php artisan test tests/Unit/TechnicalLogArchiveTest.php tests/Feature/LogRetentionTest.php. Existing database retention remains unchanged. Live provider retention and archive storage are not verified by these tests.
