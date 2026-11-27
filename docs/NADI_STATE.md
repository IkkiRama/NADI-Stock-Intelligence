# NADI Project State (`docs/NADI_STATE.md`)

**Last Updated**: 2026-11-27
**Project Path**: `D:\laragon\www\nadi`
**Current Phase**: Phase 4 Complete — Ready for **Phase 5 (Portfolio Command Center)**

---

## 1. Completed Modules
### Phase 0 & Phase 1 (Foundation & Premium UI)
- Laravel 12.74 + Inertia v2 + Ziggy + React 19 + TypeScript + Tailwind CSS v4 monolith (`0be0930`).
- Authentication, `EnsureUserIsActive`, RBAC + audit-logged Admin Panel (`/admin`), ID/EN i18n, Workspace Preferences (`/settings/preferences`), Editorial Fintech design system, Command Palette (`Ctrl/Cmd+K`), Overview Dashboard (`/dashboard`), Global Markets (`/markets`, `/markets/indices/{code}`), Stock Explorer (`/stocks`), Watchlist, and PWA asset cache.

### Phase 2 (Market Intelligence)
- **FX & Importers**: `FxConversionService`, `CsvMarketImporter` + `CsvSanitizer`, `ConfigurableHttpEodProvider`, `/admin/imports`, `php artisan nadi:refresh-analytics` (`fee3db8`).
- **Quantitative Analytics**: `FundamentalCalculator`, `TechnicalIndicatorService`, `StockScoringService` (`/admin/scoring`).
- **Market Intelligence UI**: **Market Heatmap** (`/heatmap`), **Stock Screener** (`/screener`), upgraded **Stock Detail** & `PriceChart`.

### Phase 3 (Stock Safety, Trading Signals, Position Sizing & Alerts)
- `StockSafetyService` (statistical anomaly & regulatory risk 0–100 + safeguard triggers), `SmartEntryExitService` (`short_term`/`swing`/`long_term`, tick-aligned levels, safeguard suppression, non-forced stances), `PositionSizingCalculator` (lot-aware exact `Dec` sizing), `AlertEvaluator`, `/opportunities`, `/stock-safety`, `/entry-exit`, `/alerts-calendar`, `/admin/safety-rules`, and `PriceChart` level/signal overlays (`95c5bca`).

### Phase 4 (Multi-Broker Portfolio Accounting & Financial Calculators)
- **Database Schema & Seeders**: `brokers`, `portfolios`, `brokerage_accounts`, `portfolio_transactions` (immutable/reversible ledger with `reversal_of_id` & `dedupe_hash`), `portfolio_holdings`, `portfolio_cost_lots` (open FIFO tax/cost lots), and `portfolio_reconciliations`; seeded `9` brokers (`BrokerSeeder`) and cross-currency demo portfolio (`DemoPortfolioSeeder`: `Stockbit Reguler (Demo)` IDR + `Interactive Brokers (Demo)` USD).
- **Exact Accounting Engine (`PortfolioAccountingService`)**:
  - Cleanly separates `cash_deposit`/`cash_withdrawal` (`net_contributions`) from investment returns.
  - Computes **Weighted Average** and **FIFO** cost basis simultaneously, satisfying the conservation invariant `realized_avg + unrealized_avg == realized_fifo + unrealized_fifo`.
  - Handles brokerage fees, exchange fees, dividend withholding taxes, stock splits, reverse splits, bonus shares, rights issues, cross-account transfers (`stock_transfer_out` / `stock_transfer_in`), oversell guards, and immutable audit reversals (`reverseTransaction`).
- **Valuation, CSV Import & Reconciliation**:
  - `PortfolioValuationService` (consolidated & per-account valuation in user reporting currency `IDR`/`USD`/`SGD`/`CNY`/`HKD` + Stock Detail `holdingStatus`).
  - `BrokerCsvImporter` (Stockbit, Ajaib, IPOT, BIONS, MOST, NADI Standard adapters, IDX lot-to-share normalization, SHA-256 duplicate prevention).
  - `PortfolioReconciliationService` (as-of statement cash & share quantity verification).
- **UI & Calculators**:
  - **My Portfolio** (`/portfolio`): multi-broker KPIs, Weighted Average/FIFO switcher, Add Account modal (explicit no-credentials/OTP guarantee), Record Transaction & Reversal modals, Ledger CSV export, Broker CSV Import tab, and Reconciliation tab.
  - **Financial Calculators** (`/calculators`): Lot/Fee/Break-Even & Realized Profit, Multi-Tranche Average Cost, Position Sizing, Dividend & Yield-on-Cost, Compound Growth (CAGR), and FX Converter.
  - Activated **Portfolio Heatmap** (`/heatmap?scope=portfolio`), **Dashboard** live `portfolio_summary` widget, and **Stock Detail** `holdingStatus` contextual panel.

---

## 2. Key Architectural Decisions
- **Framework**: Laravel 12.74 (`^12.0`, PHP `^8.2`) + Ziggy. Planned upgrade to Laravel 13 before Feb 2027 security EOL.
- **Shared Hosting Compatibility**: Monolithic Laravel + MySQL (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`), precompiled Vite assets (`npm.cmd run build`, no SSR), no Node/Python/Docker/Redis in production.
- **MySQL Query Compatibility**: No SQL window functions or CTEs; precomputed snapshot tables across quotes, analytics, safety, signals, holdings, and FIFO cost lots.
- **Decimal Arithmetic**: `brick/math` (`Dec` helper) across all accounting & financial services + explicit Eloquent `decimal:N` casts for SQLite/MySQL parity.

---

## 3. Database Migration Status
All 13 migrations applied cleanly on MySQL 8.0.30 (`nadi`), including `2026_11_27_300001_create_phase4_portfolio_accounting_tables`.
Verified MySQL demo seed counts: `9` brokers, `1` default portfolio (`admin@nadi.test`), `2` brokerage accounts, `4` active holdings, `5` open FIFO cost lots, and `9` ledger transactions alongside `35` instruments, `8` indices, `9,100` daily bars, `35` safety assessments, and `105` trading signals.

---

## 4. Verification & Test Results
- `npx.cmd tsc --noEmit`: 0 errors.
- `npm.cmd run build`: succeeded (`vite v6.1.1`, 2,643 modules transformed).
- `php artisan test`: **73 passed (807 assertions)** — 0 failed, 0 skipped (Duration: 104.60s).
  - Phase 4 Unit: `PortfolioAccountingServiceTest`, `BrokerCsvImporterTest`.
  - Phase 4 Feature: `PortfolioAndCalculatorsTest`, updated `MarketAndStockPagesTest`.
- `php artisan migrate:fresh --seed --force` on MySQL 8.0.30: succeeded (`brokers=9`, `portfolios=1`, `accounts=2`, `holdings=4`, `lots=5`, `transactions=9`).

---

## 5. Known Issues & Limitations
- **Market holiday calendar**: Empty by default (`holidays_loaded = false`) until imported via `/admin/imports` (`market_holidays` CSV).
- **Intraday VWAP**: Not derived from daily EOD bars (explicitly disclosed on `PriceChart`).
- **Live broker sync**: `supports_live_sync = false` across all brokers; NADI uses manual entry and broker CSV statement imports rather than asking for brokerage passwords or OTPs.
- **CSP header**: Deferred to Phase 12 hardening.

---

## 6. Next Actionable Tasks — Phase 5 (Portfolio Command Center)
1. Expand Portfolio Overview & Multi-Account Comparison views (cost basis vs market value comparison, realized/unrealized/dividend breakdown by broker, sector, country, exchange, and currency).
2. Add Apache ECharts allocation visualizations (donut, stacked bar, treemap, actual vs target placeholder hook) without duplicating asset values across accounts.
3. Implement Portfolio Concentration Analytics (Top 3 / Top 5 / Top 10 holdings, largest single-stock weight, sector/country/broker/currency concentration, HHI index, and configurable concentration warnings).
