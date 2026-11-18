#!/usr/bin/env bash
# Phase 62.5 — run every PHPUnit/Pest test file against a clean non-SQLite test DB.
# Manual-schema test classes and RefreshDatabase suites must not contaminate
# one another on MySQL or PostgreSQL.
set -euo pipefail

if [[ "${APP_ENV:-}" != "testing" ]]; then
  echo "Refusing database reset: APP_ENV must be testing." >&2
  exit 1
fi
if [[ "${DB_CONNECTION:-}" != "mysql" && "${DB_CONNECTION:-}" != "pgsql" ]]; then
  echo "Only the CI MySQL/PostgreSQL connections are permitted." >&2
  exit 1
fi
if [[ "${DB_DATABASE:-}" != "friendship_on_hotel_test" ]]; then
  echo "Refusing database reset: expected exact CI-only database name." >&2
  exit 1
fi

mapfile -t test_files < <(find tests/Feature tests/Unit -type f -name '*Test.php' | LC_ALL=C sort)
if [[ "${#test_files[@]}" -eq 0 ]]; then
  echo "No test files discovered; refusing to claim success." >&2
  exit 1
fi

passed=0
for file in "${test_files[@]}"; do
  echo "::group::${DB_CONNECTION}: ${file}"
  # The CI database is disposable. Drop all schemas in a database-aware manner
  # rather than dropping parent tables while foreign keys still reference them.
  php artisan db:wipe --force --no-interaction --quiet
  php artisan test "${file}" --no-ansi
  echo "::endgroup::"
  passed=$((passed + 1))
done
echo "${DB_CONNECTION}: ${passed} isolated test files passed"
