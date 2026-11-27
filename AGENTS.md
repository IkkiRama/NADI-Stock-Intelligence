# AGENTS.md — NADI Repository Guide

## Project Location & Toolchain (Local Laragon / Windows)
- Project root: `D:\laragon\www\nadi`
- PHP 8.3.7: `D:\laragon\bin\php\php-8.3.7-Win32-vs16-x64\php.exe`
- Composer: `D:\laragon\bin\composer\composer.phar` (run via PHP)
- Node.js 20.14.0 LTS (`npm 10.7.0`): `D:\laragon\bin\nodejs\node-v20.14.0-win-x64\node-v20.14.0-win-x64`
  - **Note**: `node-v22.14.0-win-x64` on this machine has an incomplete `npm` installation; always use Node 20 above and invoke `npm.cmd` / `npx.cmd` in PowerShell (because `.ps1` execution policy is disabled).
- MySQL 8.0.30: `D:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe`
- Git 2.37.3: `D:\laragon\bin\git\bin\git.exe`

Standard PowerShell PATH prefix:
```powershell
$env:Path = 'D:\laragon\bin\php\php-8.3.7-Win32-vs16-x64;D:\laragon\bin\nodejs\node-v20.14.0-win-x64\node-v20.14.0-win-x64;D:\laragon\bin\git\bin;D:\laragon\bin\mysql\mysql-8.0.30-winx64\bin;' + $env:Path; Set-Location D:\laragon\www\nadi
```

## Architecture & Conventions
- **Stack**: Monolithic Laravel 12.74 + Inertia.js v2 + Ziggy + React 19 + TypeScript + Tailwind CSS v4.
- **No SSR / No Node in production**: Always build assets locally with `npm.cmd run build` (do not use `build:ssr`). Production runs purely on PHP + MySQL + cPanel cron.
- **Domain layout**:
  - `app/Domain/<Module>/{Services,Actions,Contracts,Support}`
  - `app/Support/Math/Dec.php` — shared exact decimal math (`brick/math`).
  - `app/Models/` — Eloquent models.
- **Decimal rules**:
  - Never use binary `float` for stored prices, monetary amounts, quantities, or returns.
  - Transport decimal columns as strings from PHP to React; convert to `Number` only for display formatting or chart rendering.
  - Default precision: prices `decimal(20,6)`, money `decimal(24,4)`, ratios/returns (fractions) `decimal(16,8)`, rounding `HALF_UP`.
- **SQL compatibility**:
  - Target MySQL 5.7.8+ / MariaDB 10.3+ / MySQL 8.0+. **Do not use SQL window functions or CTEs in application queries.** Use precomputed `*_quotes_latest` tables.
- **Data honesty & Demo labeling**:
  - Synthetic/demo data must always be flagged `is_demo = true` and visibly labeled in the UI.
  - Never fabricate live feeds, unimported holidays, or intraday bars from EOD data.
- **Verification commands**:
  - `php artisan test`
  - `npx.cmd tsc --noEmit`
  - `npm.cmd run build`
  - `php artisan migrate:fresh --seed --force`
