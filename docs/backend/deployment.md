# Deployment checklist (OGA Awards V2)

## Before the first upload

- [ ] Rotate every secret that ever lived in the old repo: Flutterwave keys and webhook hash, SMTP password. Revoke the GitHub token that was once pasted in chat.
- [ ] Delete the "Development only" block at the bottom of the root `.htaccess` (it disables JS/CSS caching).
- [ ] Put the site on HTTPS first. Set `APP_URL` to the https address with a trailing slash.

## backend/config.php on the server (never committed)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] Production database name, user and a strong password (a user with rights on that one database only)
- [ ] Live Flutterwave keys: `FLW_PUBLIC_KEY`, `FLW_SECRET_KEY`, `FLW_WEBHOOK_HASH`
- [ ] SMTP: `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_SECURE` (tls or ssl), `MAIL_FROM`, `MAIL_FROM_NAME`

## Server

- [ ] PHP 8.1+ with extensions: pdo_mysql, mbstring, curl, gd (with WebP), fileinfo, openssl
- [ ] php.ini: `upload_max_filesize = 6M`, `post_max_size = 8M` (committee and nominee photos allow up to 5 MB)
- [ ] `backend/storage/cache` and `uploads/` writable by the web server; nothing else writable
- [ ] Confirm these addresses are REFUSED (403/404) in a browser: `/backend/config.php`, `/database/migrations/001_schema.sql`, `/docs/`, `/.git/config`
- [ ] If the site sits behind Cloudflare or another proxy, tell the developer first: rate limits use the connecting address

## Database

- [ ] Create the database, then run `php backend/tools/migrate.php` (applies migrations 001 to 006)
- [ ] `php backend/tools/create-super-admin.php`: use a long unique password; do NOT run `load-demo-data.php` in production
- [ ] Daily backup: `mysqldump` of the database plus a copy of `uploads/`. Do one test restore.

## Payments

- [ ] Flutterwave dashboard: webhook URL `https://<your-site>/api/webhooks/flutterwave` and the same secret hash as `FLW_WEBHOOK_HASH`
- [ ] Make a real small test payment (vote and ticket), confirm it credits exactly once, then refund it

## Scheduled tasks (Windows Task Scheduler or cron)

- [ ] Every 10 minutes: `php backend/tools/reconcile-payments.php` (buyers who never returned from the payment page)
- [ ] Every 5 minutes: `php backend/tools/send-outbox.php` (retries failed emails)

## Go-live checks

- [ ] Send a test email: nominee "forgot password" arrives, the link fills the code
- [ ] Sign in as admin; every sidebar page loads; sign out works
- [ ] Public pages (home, vote, tickets, committee, contact) load; the contact form reaches Admin > Messages
- [ ] Scanner opens on a phone and the camera starts (needs HTTPS)
- [ ] Run `tests/run.php` on a COPY of the site before the first release (it never touches the live database)
