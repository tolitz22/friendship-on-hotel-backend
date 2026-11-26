# PN-3 pilot worker

Disabled by default. The production API must not send Web Push inline.

Before enabling, install the maintained transport using Composer in a PHP 8.3+ environment:
`composer require minishlink/web-push`
Commit both composer.json and composer.lock, run backend CI, and deploy the identical revision to the API and worker. The transport dependency is intentionally not added to the manifest alone because the lock file must be resolved together.

Generate a VAPID key pair using the installed library's VAPID::createVapidKeys() in a secure local shell. Set PMS_PUSH_PUBLIC_KEY, PMS_PUSH_PRIVATE_KEY and PMS_PUSH_SUBJECT (mailto: or HTTPS contact), PMS_PUSH_HOTEL_ID (Client Demo only), and PMS_PUSH_ENABLED=true on a dedicated worker only. Share database and APP_KEY with API. Never expose the private key.

Railway worker start command: php artisan schedule:work
Run migrations on the API deployment before enabling the worker. No volume required. Keep one worker for the pilot.

The database outbox deduplicates per subscription and alert ID, retries up to five times, and checks active admin membership at delivery. Delivery is at-least-once; client-side notification tags will be required. Existing alerts may be sent on first activation. This polls current alerts, not a durable business event stream. Logout/device ownership revocation and precise deep links must be implemented before real-hotel activation.
