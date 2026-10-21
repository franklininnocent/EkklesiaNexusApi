# Local database safety

## What happened

`php artisan test` uses `RefreshDatabase`, which runs **`migrate:fresh`** (drops all tables, then re-rigrates). If PHPUnit points at **`EkklesiaNexusDb`** (for example when `bootstrap/cache/config.php` is present and bakes in `.env` Postgres settings), **all parish data in that database is destroyed**.

## Protections now in place

1. **`DB_PROTECTED_DATABASES`** — default `EkklesiaNexusDb`. `tests/TestCase.php` throws before `migrate:fresh` if the active database name is protected.
2. **`phpunit.xml`** — forces tests to use **`EkklesiaNexusDb_test`** (not the dev database).
3. **`.env.testing`** — documents the test database connection (create `EkklesiaNexusDb_test` in PostgreSQL once).

## Rules for developers

- **Never** run `php artisan test` while `bootstrap/cache/config.php` exists unless you have verified `config('database.connections.pgsql.database')` is **`EkklesiaNexusDb_test`**.
- After production-style optimize, run **`php artisan config:clear`** before local dev or tests.
- **Never** run `migrate:fresh`, `migrate:refresh`, or `db:wipe` against `EkklesiaNexusDb` unless you explicitly intend to reset that environment and have a backup.
- Keep a regular **`pg_dump`** of `EkklesiaNexusDb` before risky operations.

## Restore dev data (no backup)

```bash
php artisan config:clear
php artisan db:seed --force
php scripts/recover-sacred-heart-parish.php
php artisan tenants:seed-demo-data --tenant=<id> --force
```

Demo parish admin (defaults): `shadmin@gmail.com` / `TempPassword123!` (see `docs/platform/tenant-demo-data-seeding.md`).

Super admin: `franklininnocent.fs@gmail.com` — password from `SUPER_ADMIN_PASSWORD` in `.env`.
