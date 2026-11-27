# NADI Project State (`docs/NADI_STATE.md`)

**Last Updated**: 2026-11-27
**Project Path**: `D:\laragon\www\nadi`
**Current Phase**: Phase 5 Complete — Ready for **Phase 6 (Advanced Performance & Benchmark Center)**

---

## 1. Completed Modules
### Phase 0 – Phase 4 Summary
- **Phase 0 & 1** (`0be0930`): Laravel 12.74 + Inertia v2 + Ziggy + React 19 + TypeScript + Tailwind CSS v4 monolith, Auth, RBAC + Audit Admin, ID/EN i18n, Workspace Preferences, Command Palette, Dashboard, Global Markets, Stock Explorer, Watchlist, PWA.
- **Phase 2** (`fee3db8`): `FxConversionService`, `CsvMarketImporter` + `CsvSanitizer`, `ConfigurableHttpEodProvider`, `FundamentalCalculator`, `TechnicalIndicatorService`, `StockScoringService`, Heatmap, Screener, upgraded Stock Detail & `PriceChart`.
- **Phase 3** (`95c5bca`): `StockSafetyService`, `SmartEntryExitService`, `PositionSizingCalculator`, `AlertEvaluator`, `/opportunities`, `/stock-safety`, `/entry-exit`, `/alerts-calendar`, `/admin/safety-rules`.
- **Phase 4** (`176ae34`): `PortfolioAccountingService` (Weighted-Average + FIFO cost basis, cash vs returns, fees/taxes, splits/bonus/rights, transfers, immutable reversals), `BrokerCsvImporter`, `PortfolioReconciliationService`, `/portfolio`, `/calculators`, `/heatmap?scope=portfolio`.

### Phase 5 (Portfolio Command Center)
- **Command Center Valuation (`PortfolioValuationService`)**:
  - Full consolidated KPI strip (Total Equity, Equity Market Value, Total Invested Cost, Net Contributions, Cash Balance, Unrealized P&L, Realized P&L + Dividends, Total P&L + Return %, Daily Change + %, Holdings/Accounts count, Risk Status, Last Synced Timestamp).
  - Side-by-side **Multi-Account Comparison** (`accountComparison` across IDR & global USD accounts with portfolio weight shares).
- **Historical Cost Basis vs Market Value (`PortfolioHistoryService`, `CostVsMarketChart`)**:
  - Reconstructs daily `invested_cost`, `market_value`, `cash_balance`, `net_contributions`, `total_equity`, and `unrealized_pnl` chronologically in bounded batch queries under both Weighted-Average & FIFO methods, visualized with Apache ECharts time-series (`1M`/`3M`/`6M`/`YTD`/`1Y`) and per-stock Cost vs Market grouped bar charts.
- **Multi-Dimensional Asset Allocation & Concentration (`PortfolioAnalyticsCommandCenterService`, `AllocationPanel`, `ConcentrationPanel`)**:
  - 10 non-duplicating allocation dimensions (`by_sector`, `by_stock`, `by_industry`, `by_country`, `by_exchange`, `by_currency` Stocks + Cash, `by_broker`, `by_size_tier`, `by_safety`, `by_asset_class`) viewable as Donut, Bar, or Treemap + exact tables.
  - Top 3 / Top 5 / Top 10 weights, Largest Single-Stock Exposure, Herfindahl-Hirschman Index (`HHI` 0–10,000), Effective Holdings ($N_{\text{eff}}$), and configurable concentration thresholds (`portfolios.concentration_thresholds` via `PATCH /portfolio/concentration-thresholds`).

---

## 2. Key Architectural Decisions
- **Framework**: Laravel 12.74 (`^12.0`, PHP `^8.2`) + Ziggy. Planned upgrade to Laravel 13 before Feb 2027 security EOL.
- **Shared Hosting Compatibility**: Monolithic Laravel + MySQL (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`), precompiled Vite assets (`npm.cmd run build`, no SSR), no Node/Python/Docker/Redis in production.
- **MySQL Query Compatibility**: No SQL window functions or CTEs; precomputed snapshot tables + bounded batch replay in PHP (`Dec`).
- **Decimal Arithmetic**: `brick/math` (`Dec` helper) across all accounting & portfolio analytics services + explicit Eloquent `decimal:N` casts.

---

## 3. Database Migration Status
All 14 migrations applied cleanly on MySQL 8.0.30 (`nadi`), including `2026_11_27_400001_add_concentration_thresholds_to_portfolios_table`.

---

## 4. Verification & Test Results
- `npx.cmd tsc --noEmit`: 0 errors.
- `npm.cmd run build`: succeeded (`vite v6.1.1`, 2,646 modules transformed).
- `php artisan test`: **75 passed (836 assertions)** — 0 failed, 0 skipped (Duration: 129.40s).
  - Phase 5 Unit: `PortfolioCommandCenterServiceTest`, `PortfolioHistoryServiceTest`.
  - Phase 5 Feature: updated `PortfolioAndCalculatorsTest` covering Command Center props & `PATCH /portfolio/concentration-thresholds`.
- `php artisan migrate:fresh --seed --force` on MySQL 8.0.30: succeeded cleanly across all seeders (`BrokerSeeder`, `DemoMarketIntelligenceSeeder`, `DemoPortfolioSeeder`, etc.).
- HTTP smoke test (`/` and `/login`): HTTP `200`.

---

## 5. Known Issues & Limitations
- **Market holiday calendar**: Empty by default (`holidays_loaded = false`) until imported via `/admin/imports` (`market_holidays` CSV).
- **Intraday VWAP**: Not derived from daily EOD bars (explicitly disclosed on `PriceChart`).
- **Live broker sync**: `supports_live_sync = false` across all brokers; NADI uses manual entry and broker CSV statement imports rather than asking for brokerage passwords or OTPs.
- **CSP header**: Deferred to Phase 12 hardening.

---

## 6. Next Actionable Tasks — Phase 6 (Advanced Performance & Benchmark Center)
1. Implement **Time-Weighted Return (TWR)** with subperiod chaining across external cash flows (`cash_deposit`, `cash_withdrawal`, `stock_transfer_*`).
2. Implement **Money-Weighted Return (XIRR)** with dated cash flows, terminal portfolio valuation, and safe non-convergence / ambiguous-root handling.
3. Implement **Portfolio Performance Attribution** (sector/country/stock contribution to return, currency translation effects, dividend & fee effects reconciling to total portfolio return) and **Benchmark Center** (`/portfolio-analytics`, comparing normalized portfolio curves vs `IHSG`, `LQ45`, `SPX`, `NDX`, `DJI`, `STI`, `CSI300`, `HSI` with excess return, rolling return, tracking error, information ratio, and price vs total-return index disclosures).
