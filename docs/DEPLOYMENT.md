# NADI cPanel Shared-Hosting Deployment Guide

## Server Requirements
- **PHP**: `>= 8.2` (tested on PHP `8.3.7`)
- **Required PHP Extensions**: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd`, `hash`, `intl`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, `xml`, `zip`
- **Database**: MySQL `>= 5.7.8` (recommended `8.0+`) or MariaDB `>= 10.3`
- **No Node.js, Python, Docker, or Redis required on the production server.**

## 1. Build Assets Locally
Before uploading to cPanel, build production frontend bundles on your development machine:
```bash
npm ci
npm run build
```
Verify `public/build/manifest.json` and `public/build/assets/*` are generated. Never run `build:ssr`.

## 2. Upload & Directory Structure
1. Upload the project files (including `vendor/` if SSH/Composer is unavailable on cPanel, and `public/build/`) to `/home/<cpanel_user>/nadi/`.
2. Point the domain/subdomain Document Root in cPanel to `/home/<cpanel_user>/nadi/public`.
   - *Alternative if Document Root cannot be changed from `public_html`*: copy the contents of `public/` into `public_html/` and update `public_html/index.php` paths (`__DIR__.'/../nadi/vendor/autoload.php'` and `'../nadi/bootstrap/app.php'`).
3. Ensure `storage/` and `bootstrap/cache/` are writable (`775`).
4. If `symlink()` is disabled in PHP on your host, omit `php artisan storage:link` or map public uploads directly via controller/route when introduced in Phase 2+.

## 3. Production `.env` Configuration
```ini
APP_NAME=NADI
APP_ENV=production
APP_KEY=base64:... # generate via php artisan key:generate
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_URL=https://your-domain.example

APP_LOCALE=id
APP_FALLBACK_LOCALE=en

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_cpanel_db
DB_USERNAME=your_cpanel_db_user
DB_PASSWORD=your_strong_password

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=sync

NADI_DEMO_DATA=true # set false when live/imported data is configured
NADI_ADMIN_EMAIL=admin@your-domain.example
NADI_ADMIN_PASSWORD=your-strong-initial-password
```

## 4. Database Setup & Caching
Run via cPanel Terminal / SSH:
```bash
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan nadi:doctor
```

## 5. Security Hardening Verification (`v1.0.0`)
- All web responses emit per-request cryptographic nonces for `@routes` and `@vite` under strict `Content-Security-Policy` (`script-src 'self' 'nonce-...'` without `'unsafe-inline'` or `'unsafe-eval'`), `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `X-Permitted-Cross-Domain-Policies: none`, and `Strict-Transport-Security` on HTTPS.
- `public/.htaccess` blocks hidden dotfiles (`.env`, `.git`, etc. except `.well-known/`) and sets immutable caching for hashed `/build/assets/*` bundles.

## 5. cPanel Cron Job (Laravel Scheduler)
Add a single cron job running every minute:
```cron
* * * * * /usr/local/bin/php /home/<cpanel_user>/nadi/artisan schedule:run >> /dev/null 2>&1
```
Adjust `/usr/local/bin/php` to your host's PHP 8.2/8.3 CLI binary path (e.g., `/opt/cpanel/ea-php83/root/usr/bin/php`).

## 6. Backups & Restoration
- Schedule daily MySQL dumps via cPanel Backup Wizard or a cron `mysqldump` task retaining at least 7–30 days of encrypted archives.
- Back up `.env` securely offline (never commit `.env` to Git).
- To restore: import the SQL dump into a clean MySQL database, restore `.env`, and run `php artisan optimize:clear && php artisan config:cache && php artisan route:cache`.
