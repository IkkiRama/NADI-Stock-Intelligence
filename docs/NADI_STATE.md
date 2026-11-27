# NADI Project State (`docs/NADI_STATE.md`)

**Last Updated**: 2026-11-27
**Project Path**: `D:\laragon\www\nadi`
**Current Phase**: Phase 2 Complete — Ready for **Phase 3 (Stock Safety & Trading Signals)**

---

## 1. Completed Modules
### Phase 0 & Phase 1 (Foundation & Premium UI)
- Laravel 12.74 + Inertia v2 + Ziggy + React 19 + TypeScript + Tailwind CSS v4 monolith (`0be0930`).
- Authentication, `EnsureUserIsActive`, RBAC + audit-logged Admin Panel (`/admin`), ID/EN i18n, Workspace Preferences (`/settings/preferences`), Editorial Fintech design system, Command Palette (`Ctrl/Cmd+K`), Overview Dashboard (`/dashboard`), Global Markets (`/markets`, `/markets/indices/{code}`), Stock Explorer (`/stocks`), Watchlist, and PWA asset cache.

### Phase 2 (Market Intelligence)
- **FX & Importers**:
  - `FxConversionService` (direct, inverse, and IDR-triangulated lookup).
  - `CsvMarketImporter` + `CsvSanitizer` supporting idempotent chunked CSV uploads for `prices_daily`, `market_holidays`, `financial_statements`, and `corporate_actions` with OHLC invariant validation and spreadsheet formula-injection protection.
  - `ConfigurableHttpEodProvider` adapter stub (`config/services.php` `market_data_eod`; never fabricates data when unconfigured).
  - Admin Imports & Sync center (`/admin/imports`, `can:manage-imports`) with `DataImportRun` audit history and batch analytics trigger (`php artisan nadi:refresh-analytics`, scheduled daily in `routes/console.php`).
- **Quantitative Analytics Engines**:
  - `FundamentalCalculator`: point-in-time (`published_at <= asOfDate`), extraordinary items adjustment, cross-currency statement conversion (`ADRO` USD→IDR demo case), negative earnings handling (`GOTO` `per = null`), and Financials sector rules (`DER = total_liabilities / total_equity`, `current_ratio`/`FCF` omitted).
  - `TechnicalIndicatorService`: SMA(20/50/200), EMA(20/50), Wilder RSI(14), MACD(12,26,9), Bollinger Bands(20,2), Wilder ATR(14), OBV, and annualized 20D volatility.
  - `StockScoringService`: transparent 0–100 scoring across 5 configurable weights (default 30/25/20/15/10), `data_coverage`, `sector_percentile`, `reasons`, `missing_indicators`, and `/admin/scoring` configuration UI (`can:manage-scoring`).
- **Market Intelligence UI**:
  - Interactive **Market Heatmap** (`/heatmap`) powered by tree-shaken Apache `echarts` v6 (market/watchlist scope, exchange/sector filters, size/color metrics, fullscreen, drill-down).
  - **Stock Screener** (`/screener`) with multi-factor filters, sorting, saved user presets (`saved_screeners`), watchlist toggle, and formula-safe CSV export (`/screener/export`).
  - Upgraded **Stock Detail** (`/stocks/{exchange}/{symbol}`) and `PriceChart` (`lightweight-charts` v5): SMA/EMA/Bollinger overlays, benchmark `%` comparison overlay, synchronized RSI/MACD/ATR/OBV sub-pane, `createSeriesMarkers` corporate-action markers, PNG screenshot download, fundamentals, statements, technical summary, and score breakdown.

---

## 2. Key Architectural Decisions
- **Framework**: Laravel 12.74 (`^12.0`, PHP `^8.2`) + Ziggy. Planned upgrade to Laravel 13 before Feb 2027 security EOL.
- **Shared Hosting Compatibility**: Monolithic Laravel + MySQL (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`), precompiled Vite assets (`npm.cmd run build`, no SSR), no Node/Python/Docker/Redis in production.
- **MySQL Query Compatibility**: No SQL window functions or CTEs; precomputed snapshot tables (`*_quotes_latest`, `instrument_fundamentals_latest`, `instrument_technicals_latest`, `instrument_scores`).
- **Decimal Arithmetic**: `brick/math` (`Dec` helper) across backend services + explicit Eloquent `decimal:N` casts for SQLite/MySQL parity.

---

## 3. Database Migration Status
All 11 migrations applied cleanly on MySQL 8.0.30 (`nadi`), including `2026_11_27_100001_create_phase2_market_intelligence_tables`.
Verified MySQL demo seed counts: `35` instruments, `8` indices, `9,100` daily equity bars, `70` financial statements, `35` corporate actions, `35` fundamental snapshots, `35` technical snapshots, `35` stock scores.

---

## 4. Verification & Test Results
- `php artisan test`: **58 passed (556 assertions)** — 0 failed, 0 skipped (Duration: 45.10s).
  - Unit: `DecTest`, `TickSizeTest`, `PriceReturnServiceTest`, `MarketClockServiceTest`, `DemoMarketDataProviderTest`, `RefreshLatestQuotesTest`, `FundamentalCalculatorTest`, `TechnicalIndicatorServiceTest`, `StockScoringServiceTest`, `CsvMarketImporterTest`, `ExampleTest`.
  - Feature: `DashboardTest`, `GlobalSearchTest`, `PreferencesAndLocalizationTest`, `MarketAndStockPagesTest`, `AdminAccessAndAuditTest`, `HeatmapAndScreenerTest`, `AdminImportAndScoringTest`, plus starter-kit `Auth/*` and `Settings/*` suites.
- `npx.cmd tsc --noEmit`: 0 errors.
- `npm.cmd run build`: succeeded (`vite v6.1.1`, 2,634 modules transformed).
- `php artisan migrate:fresh --seed --force` on MySQL 8.0.30: succeeded (`fund=35`, `tech=35`, `scores=35`).
- HTTP smoke test (`php artisan serve --port=8124`): `/` → HTTP `200`, `/login` → HTTP `200`.

---

## 5. Known Issues & Limitations
- **Market holiday calendar**: Empty by default (`holidays_loaded = false`) until imported via `/admin/imports` (`market_holidays` CSV).
- **Intraday VWAP**: Not derived from daily EOD bars (explicitly disclosed on `PriceChart`).
- **Lot size verification**: SGX/HKEX board lots remain flagged `lot_size_verified = false`.
- **CSP header**: Deferred to Phase 12 hardening.

---

## 6. Next Actionable Tasks — Phase 3 (Stock Safety & Trading Signals)
1. Implement **NADI Stock Safety** engine & schema (`stock_safety_assessments`): detect abnormal price moves, volume spikes, volatility shocks, thin liquidity, price gaps, fundamental divergence, and suspension/monitoring flags (0–100 risk score, category, triggered rules, data confidence, and never asserting definitive manipulation).
2. Implement **Smart Entry & Exit** planner (`trading_signals` & signal history): rule-based entry zone, confirmation, stop-loss, multiple profit targets, risk/reward ratio, position sizing, capital at risk, invalidation rules, and exchange-specific `TickSize` / lot rules; suppress BUY signals when Stock Safety safeguards trigger.
3. Add **Chart Overlays** (support/resistance levels, entry/stop-loss/target lines, historical signal markers on `PriceChart`).
4. Build **Opportunities**, **Stock Safety**, **Smart Entry & Exit**, and **Alerts & Calendar** UI modules (replacing their Phase 3 `/segera/*` placeholders).
