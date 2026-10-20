# EkklesiaNexusApi deployment

This document is the deployment procedure for the Laravel API only. It was written from the repository on 15 Oct 2026. It does not mean a UAT or Production server has been provisioned or that a release has been deployed.

Parish application hosting is in `EkklesiaNexusUi/docs/DEPLOYMENT.md`. Marketing site hosting is in `EkklesiaNexusWeb/docs/DEPLOYMENT.md`.

## Status of this workspace

The directory that was inspected is Ubuntu 24.04.3 LTS with PHP 8.4.13, Nginx 1.24.0, and PostgreSQL client 16.15. It is not declared to be UAT or Production. Do not treat paths under `/var/www/html/EkklesiaNexus` as the release layout below. `public/storage` on that tree has pointed at an unrelated EkklesiaSoft storage path and must not be copied.

Git remote URL, server addresses, database names, and secrets were not present as approved deployment inputs. Every unknown value is a placeholder in angle brackets.

## What this application is

| Item | Value in this repository |
| --- | --- |
| Framework | Laravel 13.37.0 (`laravel/framework` ^13.0, lock `v13.37.0`) |
| PHP constraint | `^8.3` |
| Authentication | Laravel Passport 13.x, guard `api` |
| Modules | nwidart/laravel-modules 13.0.0 |
| Database driver expected in UAT and Production | `pgsql` |
| Cache default | `redis` through `predis/predis` 2.4.1 |
| Queue default | `database` |
| Session default | `database` |
| Health route | `GET /up` |
| Public web root | `public/` only |
| Migrations | about 230 files under `database/migrations` and `Modules/*/database/migrations` |
| Production dependency install | `composer install --no-dev --optimize-autoloader` |
| Tests | `composer test` (`php artisan test`) |

Do not run `composer update` as a deployment step. Do not upgrade Laravel to a newer major as part of deployment.

## Supported runtime for a new server

Verified against vendor support pages on 15 Oct 2026. Install the Ubuntu archive package, then record the exact patch version the package manager selected.

| Component | Use on a new UAT or Production VM | Support note | Source |
| --- | --- | --- | --- |
| Ubuntu Server | 26.04 LTS | Standard support to May 2031 | https://ubuntu.com/about/release-cycle |
| PHP | 8.5 from the Ubuntu 26.04 archive | Laravel 13 supports 8.3–8.5. Active PHP support to 31 Dec 2027 | https://www.php.net/supported-versions.php and https://laravel.com/docs/13.x/releases |
| PostgreSQL | 18.6 on a new empty database | PostgreSQL 18 is supported to 14 Nov 2030. Laravel 13 supports PostgreSQL 10+. Pin the Docker image to `postgres:18.6` | https://www.postgresql.org/support/versioning/ |
| Nginx | Ubuntu 26.04 package 1.28.x | Distro security updates. Do not switch to Nginx Current only because the number is higher | https://packages.ubuntu.com/resolute/nginx |
| Composer | Composer 2, current stable | Signature-checked installer from https://getcomposer.org/download/ | https://getcomposer.org/download/ |
| Cache | Distro Redis-protocol server | `apt-cache policy valkey-server redis-server`. Use whichever package Ubuntu 26.04 provides. The application speaks the Redis protocol through Predis | Application `config/database.php` and `config/cache.php` |

CI in `.github/workflows/ci.yml` currently runs PHP 8.5 and PostgreSQL 18.6 (`postgres:18.6`). SQLite PHPUnit suites need `php8.5-sqlite3` (PDO sqlite). This workstation currently has PDO sqlite on PHP 8.4 only; install the 8.5 sqlite package before treating PHP 8.5 as verified. Before the first UAT migration, run `composer test` on the PHP and PostgreSQL versions that the server actually installed. If PHP 8.5 fails that suite, stop and install PHP 8.3 instead. Do not continue on an untested PHP major.

If an existing database is already PostgreSQL 16, leave it on 16 until you run an approved dump/restore or `pg_upgrade` onto 18.6. Do not dump or restore a live UAT or Production database as part of an application release.

An existing Ubuntu 24.04 server stays on 24.04. Its PHP-FPM socket name will not be `php8.5-fpm`. Confirm the socket with `ls /run/php` before writing Nginx configuration.

## Placeholders

Replace these before running a command. Do not invent them.

| Placeholder | Meaning |
| --- | --- |
| `<ENV>` | `uat` or `production` |
| `<SSH_USER>` | Account used for the first administrative connection |
| `<SSH_HOST>` | DNS name or address of that environment's server |
| `<DEPLOY_PUBLIC_KEY>` | Public key already tested for the deploy user |
| `<API_HOST>` | Production: `api.ekklesianexus.com`. UAT: the approved UAT API hostname |
| `<APP_HOST>` | Production: `app.ekklesianexus.com`. UAT: the approved UAT application hostname |
| `<MARKETING_HOST>` | Production: `ekklesianexus.com`. UAT: the approved UAT marketing hostname |
| `<GIT_REMOTE_API>` | Approved Git remote for this API |
| `<RELEASE_SHA>` | Full immutable commit SHA approved for this release |
| `<RELEASE_ID>` | Directory name, for example `20261015T093000Z-<RELEASE_SHA first 12 hex chars>` |
| `<DB_NAME>` | `ekklesia_uat` or `ekklesia_production`. Never the same database |
| `<DB_USER>` | Restricted application role, not a superuser |
| `<DB_PASSWORD>` | Generated on the server. Never commit it |

## Topology for this API

```text
Browser or Next.js BFF
        |
      Nginx TLS
        |
   PHP-FPM (public/index.php only)
        |
        +-- PostgreSQL on 127.0.0.1:5432
        +-- Redis-protocol cache on 127.0.0.1:6379
        +-- shared storage outside the release directory
```

UAT and Production are separate servers, databases, `APP_KEY` values, Passport keys, mail credentials, and payment webhook settings. UAT must not use the Production database or live Razorpay credentials.

## 1. Confirm the server

Run on the target server, as the administrative SSH user.

```bash
ssh <SSH_USER>@<SSH_HOST>
. /etc/os-release && echo "$PRETTY_NAME"
uname -m
nproc
free -h
df -h /
```

Purpose: record OS, architecture, CPU, memory, and disk before installing packages. Root is not required for these reads.

Expected: Ubuntu 26.04 LTS for a new server, `x86_64` or `aarch64`, and enough free disk for releases, PostgreSQL, and uploads. Stop if the OS is not the release you intended to provision.

## 2. Apply security updates

Run on the target server as root through `sudo`.

```bash
sudo apt-get update
sudo apt-get upgrade -y
```

Purpose: install supported security updates from the Ubuntu archive for this OS release.

Verification:

```bash
apt-cache policy php php8.5-fpm postgresql nginx
```

Expected: candidates exist for the versions in the matrix above. If `php8.5-fpm` has no candidate, stop and record the PHP package the archive actually offers. Do not add an unreviewed third-party PHP archive during deployment.

## 3. Create the deploy user

Run on the target server as root through `sudo`.

```bash
sudo adduser --disabled-password --gecos "EkklesiaNexus deploy" ekklesia
sudo mkdir -p /home/ekklesia/.ssh
sudo chmod 700 /home/ekklesia/.ssh
printf '%s\n' '<DEPLOY_PUBLIC_KEY>' | sudo tee /home/ekklesia/.ssh/authorized_keys
sudo chmod 600 /home/ekklesia/.ssh/authorized_keys
sudo chown -R ekklesia:ekklesia /home/ekklesia/.ssh
```

Purpose: a restricted user owns application files. Do not use this step to disable SSH passwords. Confirm a second session can log in with the key first:

```bash
ssh -i <PATH_TO_PRIVATE_KEY> ekklesia@<SSH_HOST> 'whoami && id'
```

Expected: `ekklesia`. Keep the original administrative session open until that succeeds.

## 4. Install API packages

Run on the target server as root through `sudo`. Package names match Ubuntu 26.04. On 24.04, replace `php8.5-*` with the PHP branch that `apt-cache policy` shows and that passed tests.

```bash
sudo apt-get install -y \
  nginx \
  postgresql postgresql-contrib \
  php8.5-fpm php8.5-cli php8.5-pgsql php8.5-mbstring php8.5-xml \
  php8.5-curl php8.5-zip php8.5-gd php8.5-bcmath php8.5-intl \
  php8.5-opcache php8.5-readline \
  unzip git acl
```

Then install the cache server that this Ubuntu release actually ships:

```bash
apt-cache policy valkey-server redis-server
sudo apt-get install -y valkey-server || sudo apt-get install -y redis-server
```

Purpose: PHP-FPM, PostgreSQL, Nginx, and a localhost Redis-protocol cache. Predis does not need the `php-redis` extension. `ext-bcmath` is required by `app/Support/MoneyMath.php`. `pdo_pgsql` is required for PostgreSQL. GD, mbstring, xml, zip, intl, and sodium (bundled in common PHP builds) are required by PDF, spreadsheet, phone, and encryption libraries.

Verification:

```bash
php -v
php -m | grep -E 'bcmath|curl|gd|intl|mbstring|openssl|pdo_pgsql|zip|sodium'
psql --version
nginx -v
sudo systemctl is-enabled nginx php8.5-fpm postgresql
```

Bind the cache to localhost and confirm a ping. Use `valkey-cli` when that is the installed client.

```bash
sudo sed -i 's/^bind .*/bind 127.0.0.1 ::1/' /etc/valkey/valkey.conf 2>/dev/null || \
  sudo sed -i 's/^bind .*/bind 127.0.0.1 ::1/' /etc/redis/redis.conf
sudo systemctl restart valkey-server 2>/dev/null || sudo systemctl restart redis-server
redis-cli ping || valkey-cli ping
```

Expected: `PONG`. PostgreSQL must not listen on a public address. Confirm:

```bash
sudo ss -lnt | grep -E '5432|6379'
```

Expected: `127.0.0.1` only.

## 5. Install Composer

Run on the target server as root through `sudo`. Follow the signature check published at https://getcomposer.org/download/ for the installer that is current on the day you run it. The pattern is:

```bash
cd /tmp
curl -fsSL -o composer-setup.php https://getcomposer.org/installer
# Compare the installer hash with the hash published on getcomposer.org before continuing.
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer --version
```

Expected: Composer 2.x. Do not install Composer 1.

## 6. PHP-FPM pool

Run on the target server as root through `sudo`. Create `/etc/php/8.5/fpm/pool.d/ekklesia.conf`. Change `8.5` if the installed branch differs.

```ini
[ekklesia]
user = ekklesia
group = ekklesia
listen = /run/php/php8.5-fpm-ekklesia.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
php_admin_value[upload_max_filesize] = 8M
php_admin_value[post_max_size] = 8M
php_admin_value[expose_php] = Off
php_admin_value[memory_limit] = 256M
```

The largest Form Request file rule in this API is 5120 kilobytes. 8M covers that request plus multipart overhead. If a later approved import needs more, raise the pool and Nginx together.

```bash
sudo systemctl restart php8.5-fpm
ls -l /run/php/php8.5-fpm-ekklesia.sock
```

## 7. PostgreSQL role and database

Run on the target server as the `postgres` OS user. Do this once per environment. Do not reuse a superuser as `DB_USERNAME`.

```bash
sudo -u postgres psql -c "CREATE ROLE <DB_USER> LOGIN PASSWORD '<DB_PASSWORD>';"
sudo -u postgres psql -c "CREATE DATABASE <DB_NAME> OWNER <DB_USER>;"
sudo -u postgres psql -d <DB_NAME> -c "REVOKE ALL ON DATABASE <DB_NAME> FROM PUBLIC;"
sudo -u postgres psql -d <DB_NAME> -c "GRANT CONNECT ON DATABASE <DB_NAME> TO <DB_USER>;"
```

`pg_hba.conf` for this host should allow the application user over local sockets or `127.0.0.1/32` with `scram-sha-256`, and should not contain a `0.0.0.0/0` trust or md5 line.

Verification, as `ekklesia`, with the password supplied through `PGPASSWORD` in the shell session and not written into a ticket:

```bash
PGPASSWORD='<DB_PASSWORD>' psql -h 127.0.0.1 -U <DB_USER> -d <DB_NAME> -c 'SELECT current_user, current_database(), version();'
```

Expected: `<DB_USER>` and `<DB_NAME>`.

Take a backup before any later migration, including the first migration on a database that already contains data:

```bash
sudo -u postgres pg_dump --format=custom --file=/var/backups/postgresql/<DB_NAME>-$(date -u +%Y%m%dT%H%M%SZ).dump <DB_NAME>
```

A backup is verified only after `pg_restore` into a separate empty database succeeds. Do not restore over the live database as a routine rollback.

## 8. Release directories

Run on the target server as root through `sudo`, then as `ekklesia`.

```bash
sudo mkdir -p /srv/ekklesianexus/api/releases \
  /srv/ekklesianexus/api/shared/storage/app/public \
  /srv/ekklesianexus/api/shared/storage/app/private \
  /srv/ekklesianexus/api/shared/storage/framework/cache \
  /srv/ekklesianexus/api/shared/storage/framework/sessions \
  /srv/ekklesianexus/api/shared/storage/framework/views \
  /srv/ekklesianexus/api/shared/storage/logs
sudo chown -R ekklesia:ekklesia /srv/ekklesianexus
sudo chmod 750 /srv/ekklesianexus/api/shared
```

Persistent uploads, logs, Passport keys, and `.env` stay in `shared/`. Routine cleanup may delete old directories under `releases/` except `current` and the previous known-good release.

## 9. Fetch the approved commit

Run on the target server as `ekklesia`.

```bash
cd /srv/ekklesianexus/api/releases
git clone --no-checkout <GIT_REMOTE_API> <RELEASE_ID>
cd <RELEASE_ID>
git fetch --tags origin
git checkout --detach <RELEASE_SHA>
git rev-parse HEAD
git status --short
```

Expected: `HEAD` equals `<RELEASE_SHA>` and the work tree is clean. Do not deploy uncommitted files.

Link shared paths before installing or running Artisan:

```bash
rm -rf storage
ln -sfn /srv/ekklesianexus/api/shared/storage storage
ln -sfn /srv/ekklesianexus/api/shared/.env .env
mkdir -p bootstrap/cache
chmod 775 bootstrap/cache
```

## 10. Environment file

Create `/srv/ekklesianexus/api/shared/.env` on the server as `ekklesia`. Mode `640`, owner `ekklesia`. Never copy `.env` from another environment or from a developer machine.

Generate `APP_KEY` only when this environment has never had one:

```bash
php artisan key:generate --show
```

Put that value into `APP_KEY` and keep the same value for the life of that database. Replacing it invalidates encrypted payloads and sessions.

Required production and UAT values:

```dotenv
APP_NAME=EkklesiaNexus
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<API_HOST>
FRONTEND_URL=https://<APP_HOST>
MARKETING_URL=https://<MARKETING_HOST>
LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=<DB_NAME>
DB_USERNAME=<DB_USER>
DB_PASSWORD=<DB_PASSWORD>
DB_TIMEZONE=UTC

CACHE_STORE=redis
QUEUE_CONNECTION=database
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=<API_HOST>

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0
REDIS_CACHE_DB=1

FILESYSTEM_DISK=local
TRUSTED_PROXIES=127.0.0.1

MAIL_MAILER=<smtp-or-approved-transport>
MAIL_SCHEME=smtp
MAIL_HOST=<MAIL_HOST>
MAIL_PORT=587
MAIL_USERNAME=<MAIL_USERNAME>
MAIL_PASSWORD=<MAIL_PASSWORD>
MAIL_FROM_ADDRESS=contact@ekklesianexus.com
MAIL_FROM_NAME=EkklesiaNexus
# Zoho: copy MAIL_HOST from Settings → Mail Accounts → SMTP.
# India datacenter mailboxes use smtppro.zoho.in (smtppro.zoho.com returns 535).
# DigitalOcean droplets block outbound TCP 25/465/587 until SMTP is enabled in the account.
# Contact-form mail cannot reach Zoho until those ports are open from the API host.

MARKETING_BFF_SECRET=<MATCHES_NEXTJS_MARKETING_BFF_SECRET>
PLATFORM_HEALTH_TOKEN=<RANDOM_TOKEN>
```

`APP_ENV=production` and `APP_DEBUG=false` apply to UAT as well as Production so debug pages and verbose errors stay off. UAT still uses its own hostnames, database, mail sink, and payment test credentials.

`FRONTEND_URL` and `MARKETING_URL` are the only CORS origins the application should allow. Loopback hosts (`localhost`, `127.0.0.1`) are ignored when `APP_ENV=production`, even if those variables accidentally contain a local URL. Do not set `TRUSTED_PROXIES=*`.

UAT and Production must also set:

```bash
TENANT_SUBSCRIPTION_VIEW_ONLY_LOCKED_ENFORCEMENT=true
TENANT_RLS_ENABLED=true
```

Do not flip those defaults in local or PHPUnit environments. `php artisan platform:production-check` fails in production if either flag is off (RLS check applies when the database driver is PostgreSQL).

Passport private and public keys belong in `shared/storage` (`oauth-private.key`, `oauth-public.key`). Generate them once per environment with `php artisan passport:keys` after the first successful boot. Do not copy keys from another environment and do not commit them. OAuth clients are created per environment with `php artisan passport:client` as an approved setup step, not on every release.

UAT mail must not deliver to parishioners. Use the approved test mailbox or a capture transport. Razorpay and other billing credentials are stored encrypted by the billing configuration, not as a second copy of Production keys. Configure UAT with test gateway credentials only.

Keys present in `.env.example` that this API also reads, and which must be set when that feature is enabled: `FINANCIAL_AI_LLM_API_KEY`, `OPENAI_API_KEY`, `WHATSAPP_BUSINESS_*`, `TENANT_EXPORT_*`, `APPLICATION_ACCESS_*`, `GEOIP_DATABASE`, `SUBSCRIPTIONS_*`. Leave disabled features unset rather than copying Production secrets.

## 11. Install PHP dependencies and caches

Run inside the release directory as `ekklesia`.

```bash
cd /srv/ekklesianexus/api/releases/<RELEASE_ID>
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan platform:production-check --strict
```

`storage:link` creates `public/storage` pointing at `storage/app/public`. Because `storage` is the shared symlink, uploads survive the next release. Confirm the link target is under `/srv/ekklesianexus/api/shared/storage/app/public` and not another product tree.

Do not run `config:clear`, `route:clear`, `view:clear`, or `cache:clear` on a healthy production release. Clear a cache only to recover from a bad config cache, then rebuild it.

`optimize` is not required beyond the three cache commands above.

## 12. Migrations

Run only after a verified backup and written approval. Run inside the release directory as `ekklesia`.

```bash
php artisan migrate:status
php artisan migrate --force --pretend
php artisan migrate --force
php artisan migrate:status
```

`--pretend` prints SQL. Read it before `--force`. Never run `migrate:fresh`, `migrate:refresh`, `db:wipe`, or drop the database.

These migrations include tenant, family, donation, and subscription tables. A failed migration is a stop. Application rollback to the previous release does not undo schema changes. Recovery is restore-from-backup under a separate approval, or a forward fix.

**Demo data:** Production deploy must **not** run `php artisan tenants:seed-demo-data`, `tenants:seed-load-test-data`, or `migrate:fresh`. Parish demo fixtures are for local/staging only (`docs/platform/tenant-demo-data-seeding.md`). Validate with `php artisan tenants:validate-demo-data --tenant=<id> --expect-compact` when a compact demo tenant is intentionally seeded outside production.

## 13. Switch the current release

Run as `ekklesia` after the checks above succeed.

```bash
ln -sfn /srv/ekklesianexus/api/releases/<RELEASE_ID> /srv/ekklesianexus/api/current
readlink -f /srv/ekklesianexus/api/current
```

Keep the previous release directory. Do not delete it in the same step.

## 14. Nginx

Write a new site file, for example `/etc/nginx/sites-available/ekklesia-api.conf`. Do not overwrite an existing site until you have copied it aside and reviewed what it serves.

```bash
sudo cp /etc/nginx/sites-available/ekklesia-api.conf \
  /etc/nginx/sites-available/ekklesia-api.conf.bak-$(date -u +%Y%m%dT%H%M%SZ)
```

Replace `<API_HOST>` and confirm the PHP socket path.

```nginx
server {
    listen 80;
    server_name <API_HOST>;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name <API_HOST>;

    root /srv/ekklesianexus/api/current/public;
    index index.php;

    client_max_body_size 8m;

    ssl_certificate     /etc/letsencrypt/live/<API_HOST>/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/<API_HOST>/privkey.pem;

    add_header Strict-Transport-Security "max-age=31536000" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "no-referrer" always;

    access_log /var/log/nginx/ekklesia-api.access.log;
    error_log  /var/log/nginx/ekklesia-api.error.log warn;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.5-fpm-ekklesia.sock;
        fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~* ^/(?:\.env|composer\.(?:json|lock)|artisan|storage/) {
        deny all;
    }
}
```

Issue the certificate only after DNS for `<API_HOST>` answers this server:

```bash
sudo certbot --nginx -d <API_HOST>
sudo nginx -t && sudo systemctl reload nginx
curl -fsS -o /dev/null -w '%{http_code}\n' https://<API_HOST>/up
```

Expected: Nginx test syntax is ok, and `/up` returns 200. The document root is `public`. A request for `/.env` must return 403 or 404.

## 15. Queue worker and scheduler

Queue jobs exist for donations, tenant exports, notification mail, marketing contact mail, billing webhooks, and support-ticket SLA. The default connection is `database`, so Redis is not the queue. A worker is still required.

Create `/etc/systemd/system/ekklesia-queue.service` as root:

```ini
[Unit]
Description=EkklesiaNexus API queue worker
After=network.target postgresql.service

[Service]
User=ekklesia
Group=ekklesia
WorkingDirectory=/srv/ekklesianexus/api/current
ExecStart=/usr/bin/php artisan queue:work database --sleep=1 --tries=3 --timeout=120 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Scheduler commands registered in module providers:

| Command | Cadence |
| --- | --- |
| `auth:cleanup-password-recoveries` | every 15 minutes |
| `subscriptions:apply-scheduled` | every 15 minutes |
| `subscriptions:snapshot-usage` | daily 02:10 |
| `subscriptions:usage-alerts` | daily 02:40 |
| `billing:reconcile` | daily 03:15 |
| `billing:release-expired-reservations` | hourly |
| `billing:apply-scheduled-maintenance` | every 15 minutes |
| `tenants:cleanup-exports` | daily 02:30 |
| `tenants:cleanup-audit` | weekly |
| `tenants:subscription-lifecycle` | hourly |
| `mass-intentions:close-expired` | hourly |
| `mass-intentions:generate-occurrences` | daily 02:15 |
| `mass-intentions:check-generation-health` | daily 06:00 |
| `donations:generate-scheduled-dues` | daily 01:00 |
| `donations:expire-report-exports` | daily 02:30 |
| `application-access:retention` | weekly |
| `support:flush-notification-digest` | hourly |
| `support:expire-sessions` | every minute |

One scheduler entry, as `ekklesia` crontab:

```cron
* * * * * cd /srv/ekklesianexus/api/current && /usr/bin/php artisan schedule:run >> /srv/ekklesianexus/api/shared/storage/logs/scheduler.log 2>&1
```

Enable and verify:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ekklesia-queue
sudo systemctl status ekklesia-queue --no-pager
cd /srv/ekklesianexus/api/current && php artisan schedule:list
```

After each release:

```bash
sudo systemctl restart ekklesia-queue
cd /srv/ekklesianexus/api/current && php artisan queue:restart
```

`queue:restart` asks workers to exit after the current job so the new code loads.

## 16. Release checks

Run from the administrator workstation or the server. Record the HTTP status. Do not mark a check passed unless you ran it.

```bash
curl -fsS -o /dev/null -w 'up %{http_code}\n' https://<API_HOST>/up
curl -fsS -o /dev/null -w 'env %{http_code}\n' https://<API_HOST>/.env
cd /srv/ekklesianexus/api/current
php artisan platform:production-check --strict
php artisan migrate:status
php artisan queue:failed
```

Also confirm, with a non-production account on UAT and an approved smoke account on Production:

- Login and token refresh through Passport
- A tenant user cannot read another tenant's family or donation records
- Role and subscription restrictions still apply
- A small image upload and its authorized download
- `APP_DEBUG` is false (`platform:production-check --strict`)

`GET /api/platform/health` requires `PLATFORM_HEALTH_TOKEN`. Do not call it in a way that prints the token into shell history shared with others. Prefer a prompt or a root-only file mode 600.

## 17. Rollback

Application rollback, as `ekklesia`:

```bash
readlink -f /srv/ekklesianexus/api/current
ln -sfn /srv/ekklesianexus/api/releases/<PREVIOUS_RELEASE_ID> /srv/ekklesianexus/api/current
cd /srv/ekklesianexus/api/current
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
sudo systemctl restart ekklesia-queue
sudo nginx -t && sudo systemctl reload nginx
curl -fsS -o /dev/null -w '%{http_code}\n' https://<API_HOST>/up
```

Keep the failed release directory and its logs. Do not restore an older database dump over the live database to undo an application release. That can delete payments and parish records created after the dump. Database restore is a separately approved disaster-recovery action using `pg_restore` into a reviewed target.

## 18. Backups that this API depends on

- PostgreSQL: daily `pg_dump --format=custom` of `<DB_NAME>`, encrypted, stored off the server
- `shared/storage` uploads and Passport keys, encrypted, off the server
- A copy of the environment file stored in the approved secret store, not in Git

Proposed targets for approval: Production recovery point 24 hours and recovery time 4 hours; UAT 24 hours and 8 hours. These are not achieved until the backup job and a restore drill exist.

## 19. What is still unknown

- `<GIT_REMOTE_API>`, `<SSH_HOST>`, UAT hostnames, mail provider, and backup destination
- Whether the first server will be new Ubuntu 26.04 or an existing 24.04 host
- Whether PHP 8.5 has passed this application's test suite
- Passport clients and billing webhook endpoints for each environment
- No deployment command in this document has been executed against UAT or Production
