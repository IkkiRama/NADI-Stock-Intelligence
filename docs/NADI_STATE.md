# NADI Project State (`docs/NADI_STATE.md`)

**Last Updated**: 2026-11-27
**Project Path**: `D:\laragon\www\nadi`
**Current Phase**: Phase 1 Complete — Ready for **Phase 2 (Market Intelligence)**

---

## 1. Completed Modules (Phase 0 & Phase 1)
- **Foundation & Auth**: Laravel 12.74 + Inertia v2 + Ziggy + React 19 + TypeScript + Tailwind CSS v4 monolith (`laravel/react-starter-kit` v1.0.1), user authentication, `EnsureUserIsActive` enforcement, `last_login_at` tracking, baseline `SecurityHeaders`.
- **RBAC & Admin Panel (`/admin`)**: Roles (`admin`, `editor`, `user`), permission Gates in `AppServiceProvider`, Admin Dashboard, User & Role management (with self-demotion/self-deactivation protection), Data Source toggle, Feature Flag toggle (cache-invalidating), and immutable `AuditLog` tracking.
- **Localization & Preferences (`/settings/preferences`)**: Default Bahasa Indonesia (`id`) + English (`en`) via shared `lang/{locale}.json` translations (`id.json` & `en.json` key-parity tested), `SetLocale` middleware, Light/Dark/System theme, Compact/Comfortable density, Simple/Pro mode, reporting currency (`IDR` default), default market, chart timeframe, and display timezone.
- **Design System & Navigation**: Modern Editorial Fintech tokens (`#F7F8FA`/`#FFFFFF`/`#263447` light; `#111827`/`#192231`/`#E8EDF5` dark; lavender/sage/coral/ice with accessible ink variants), tabular numerals (`.num`), self-hosted `@fontsource-variable/inter`, grouped collapsible sidebar with Phase badges (`P2`–`P12`), `/segera/{slug}` planned module pages, optional contextual right panel + mobile Sheet, Command Palette (`cmdk`, `Ctrl/Cmd+K`, `/` with `/search` typo tolerance), and keyboard shortcuts (`?`, `g o/m/e/s`).
- **Market Foundation & Labeled Demo Data**:
  - Exact decimal math helper (`App\Support\Math\Dec` using `brick/math`).
  - Exchange tick-size rules (`TickSize`) & market session clock (`MarketClockService` without fabricated holidays).
  - Return analytics (`PriceReturnService`: `1D`, `1W`, `1M`, `3M`, `6M`, `YTD`, `1Y`, 52W high/low, 20D avg volume).
  - `MarketDataProvider` adapter contract + deterministic `DemoMarketDataProvider` (`SeededRandom`).
  - Precomputed `instrument_quotes_latest` & `index_quotes_latest` refreshed via `RefreshLatestQuotes` (`php artisan nadi:refresh-quotes`, scheduled daily in `routes/console.php`).
  - Overview Dashboard (`/dashboard`) with customizable widget visibility/order, Global Markets (`/markets`, `/markets/indices/{code}`), Stock Explorer (`/stocks`, `/stocks/{exchange}/{symbol}`) with TradingView `lightweight-charts` v5, and per-user Watchlist.
- **PWA**: `manifest.webmanifest`, SVG icons, `offline.html`, and `sw.js` caching only fingerprinted `/build/assets/*` in production.

---

## 2. Key Architectural Decisions
- **Framework**: Laravel 12.74 (`^12.0`, PHP `^8.2`) + Ziggy (`tightenco/ziggy`). *Planned upgrade*: upgrade to Laravel 13 before Laravel 12 security EOL (~Feb 2027).
- **Shared Hosting Compatibility**: Monolithic Laravel + MySQL (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`), precompiled Vite assets (`npm.cmd run build`, no SSR), no Node/Python/Docker/Redis in production.
- **MySQL Query Compatibility**: No SQL window functions or CTEs; latest quotes are precomputed in dedicated tables.
- **Decimal Arithmetic**: `brick/math` strings across PHP services and Inertia JSON props; formatted in browser with `Intl`.

---

## 3. Database Migration Status
All 10 migrations applied cleanly on MySQL 8.0.30 (`nadi`):
- `0001_01_01_000000_create_users_table`
- `0001_01_01_000001_create_cache_table`
- `0001_01_01_000002_create_jobs_table`
- `2026_11_27_000001_add_nadi_columns_to_users_table`
- `2026_11_27_000002_create_roles_tables`
- `2026_11_27_000003_create_user_settings_table`
- `2026_11_27_000004_create_exchanges_and_holidays_tables`
- `2026_11_27_000005_create_instruments_and_indices_tables`
- `2026_11_27_000006_create_market_data_tables`
- `2026_11_27_000007_create_user_activity_and_admin_tables`

Seeded demo counts verified on MySQL: `35` active equities across 7 exchanges, `8` benchmark indices, `9,100` daily equity bars (`35 × 260` weekdays), `35` latest equity quotes.

---

## 4. Verification & Test Results
- `php artisan test`: **49 passed (442 assertions)** — 0 failed, 0 skipped (Unit: `DecTest`, `TickSizeTest`, `PriceReturnServiceTest`, `MarketClockServiceTest`, `DemoMarketDataProviderTest`, `RefreshLatestQuotesTest`, `ExampleTest`; Feature: `DashboardTest`, `GlobalSearchTest`, `PreferencesAndLocalizationTest`, `MarketAndStockPagesTest`, `AdminAccessAndAuditTest`, plus starter-kit `Auth/*` and `Settings/*` suites).
- `npx.cmd tsc --noEmit`: 0 errors.
- `npm.cmd run build`: succeeded (`public/build/manifest.json` + chunks).
- `php artisan migrate:fresh --seed --force` on MySQL 8.0.30: succeeded.
- HTTP smoke test (`php artisan serve --port=8123`): `/` → HTTP `200`, `/login` → HTTP `200`.

---

## 5. Known Issues & Limitations
- **Market holiday calendar**: No holiday dates are seeded in Phase 1 (`holidays_loaded = false`); UI explicitly displays *"Kalender libur belum diimpor"* until Phase 2 importers populate `market_holidays`.
- **Exchange lot-size verification**: SGX and HKEX board lots carry `metadata.lot_size_verified = false` until verified against exchange master files.
- **Local Node 22 binary**: `D:\laragon\bin\nodejs\node-v22.14.0-win-x64` has broken `npm` on this host; use Node 20 (`node-v20.14.0-win-x64`) with `npm.cmd`/`npx.cmd`.
- **CSP header**: Deferred to Phase 12 hardening (baseline security headers active).

---

## 6. Next Actionable Tasks — Phase 2 (Market Intelligence)
1. Implement CSV & configurable external EOD price/index/holiday/corporate-action provider importers + `data_import_jobs` / `data_synchronization_logs` + bounded batch scheduler.
2. Add Apache ECharts (`echarts`) and build interactive **Market Heatmap** (`/heatmap`) with market/index/sector filters, sizing/color modes, drill-down, and fullscreen.
3. Build **Fundamental Engine** (financial statement schema, sector-aware ratio calculations, point-in-time valuation).
4. Build **Technical Indicator Engine** (SMA, EMA, RSI, MACD, Bollinger Bands, ATR, OBV) and integrate overlays into `PriceChart`.
5. Build **Stock Screener** (`/screener`) with multi-factor filters, sorting, saved configs, and CSV export.
6. Implement transparent 0–100 **Stock Scoring** system with configurable weights and missing-data indicators.
