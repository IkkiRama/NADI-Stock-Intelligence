# NADI Project State (`docs/NADI_STATE.md`)

**Last Updated**: 2026-11-27
**Project Path**: `D:\laragon\www\nadi`
**Current Phase**: Phase 8 Complete — Ready for **Phase 9 (Institutional Reports & Export Center)**

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

### Phase 6 (Advanced Performance & Benchmark Center)
- **Daily NAV & External Cash Flow Series (`PortfolioNavSeriesService`)**:
  - Chronologically replays active ledger transactions against preloaded `InstrumentPriceDaily` + `FxRateDaily` (`BatchFxLookup`) across `1M`, `3M`, `6M`, `YTD`, `1Y`, `ALL`, and `CUSTOM` date windows, isolating external flows (`cash_deposit`, `cash_withdrawal`, external `stock_transfer_*`) from internal trades, dividends, fees, and corporate actions.
- **Time-Weighted Return (`TwrCalculator`)**:
  - Geometric subperiod chaining (`1 + TWR = prod(1 + r_t)`) immune to deposit/withdrawal size & timing, plus monthly/annual TWR bars and `< 30D` annualization safeguard.
- **Money-Weighted Return (`XirrCalculator`)**:
  - Safeguarded Newton-Raphson + bounded grid-bracket bisection solver (`[-0.9999, 100.0]`) with Modified Dietz period return and safe status handling (`converged`, `insufficient_flows`, `no_sign_change`, `non_convergent`).
- **Performance Attribution & Benchmark Center (`PerformanceAttributionService`, `BenchmarkComparisonService`, `PortfolioAnalyticsController`, `/portfolio-analytics`)**:
  - Reconciled attribution by Stock (Top Contributors & Detractors), Sector, Country, Brokerage Account, and Return Driver (`local_price_effect_rep`, `fx_effect_rep`, `dividend_rep`, `fee_tax_rep`, `cash_fx_and_adjustments_rep`).
  - Normalized (`Base 100`), Cumulative (`%`), and `20D` Rolling Excess comparisons against `IHSG`, `LQ45`, `SPX`, `NDX`, `DJI`, `STI`, `CSI300`, and `HSI` with explicit `reporting_aligned` vs `native_index` FX warning, `index_type` (`price` vs `total_return`) disclosure, and `>= 20`-session gated Tracking Error & Information Ratio.

### Phase 7 (Risk, Drawdown & Rebalancing Studio)
- **Risk, Drawdown & Benchmark Beta (`PortfolioRiskAnalyticsService`)**:
  - Cash-flow-neutral Underwater Drawdown timeline & top 5 recovery episodes (`recovered` vs `underwater`), Annualized Volatility, Downside Deviation, Sharpe, Sortino, Calmar, Historical 95% Daily VaR & CVaR, Benchmark Beta, Correlation, $R^2$, and Upside/Downside Capture (strictly gated on `>= 20` sessions).
- **Correlation Matrix & FX Shock Sensitivity (`HoldingCorrelationAndExposureService`)**:
  - Pairwise Pearson daily return correlation table across top holdings (`>= 20` overlapping sessions guard) and deterministic `±5%` / `±10%` FX shock table across equity + cash currency exposures.
- **Lot-Aware Two-Pass Rebalancing Simulator (`PortfolioRebalancingService`, `RiskRebalancingController`, `/risk-rebalancing`)**:
  - Persistent user target weights (`portfolio_rebalance_targets` + `risk_rebalance_prefs`), presets (`equal_weight`, `current_weights`, `clear`), Pass 1 overweight SELLs + Pass 2 cash-buffer-constrained BUYs in whole exchange lots (`TickSize` rounded), exchange fee estimates, Stock Safety safeguard suppression, and pre/post HHI comparison.

### Phase 8 (Dividends, Newsroom & Research Center)
- **Dividends & Passive Income Tracker (`DividendIncomeService`, `DividendsController`, `/dividends`, `DividendIncomeChart`)**:
  - Strictly separates ledger-realized net dividends (`dividend_receipt` minus `dividend_tax`, YTD / 12M / All-Time) from estimated forward 12-month projections (`Estimate`-badged), computing withholding tax assumptions (`config('nadi.dividend_withholding_tax')` or user override), Yield on Market Value vs Yield on Cost, `payout_ratio > 85%` flags, and monthly realized vs projected ECharts cash-flow schedules.
- **Financial Newsroom (`NewsroomService`, `RssNewsAdapter`, `NewsroomController`, `/newsroom`, `SyncNewsFeeds`)**:
  - Multi-market curated briefings & offline-testable PHP `SimpleXML` RSS/Atom parser with HTTPS-only non-private-IP SSRF validation (`nadi:sync-news` scheduled hourly), portfolio/watchlist/category/country/impact filters, admin editorial publishing (`POST /newsroom/articles`), and live Dashboard & Stock Detail wiring.
- **Investment Research Center (`ResearchCenterService`, `ResearchCenterController`, `/research-center`)**:
  - User-isolated structured investment notes (`ResearchNote`) with 5 starter templates, stance/conviction tracking, currency-guarded implied target upside, catalysts/risks/invalidation rules, pinning, and `GlobalSearchService` (`CommandPalette`) integration.

---

## 2. Key Architectural Decisions
- **Framework**: Laravel 12.74 (`^12.0`, PHP `^8.2`) + Ziggy. Planned upgrade to Laravel 13 before Feb 2027 security EOL.
- **Shared Hosting Compatibility**: Monolithic Laravel + MySQL (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`), precompiled Vite assets (`npm.cmd run build`, no SSR), no Node/Python/Docker/Redis in production.
- **MySQL Query Compatibility**: No SQL window functions or CTEs; precomputed snapshot tables + bounded batch replay in PHP (`Dec`).
- **Decimal Arithmetic**: `brick/math` (`Dec` helper) across all accounting & portfolio analytics services + explicit Eloquent `decimal:N` casts.

---

## 3. Database Migration Status
All 16 migrations applied cleanly on MySQL 8.0.30 (`nadi`), including `2026_11_27_600001_create_phase8_news_research_tables`.

---

## 4. Verification & Test Results
- `npx.cmd tsc --noEmit`: 0 errors.
- `npm.cmd run build`: succeeded (`vite v6.1.1`, 2,654 modules transformed).
- `php artisan test`: **92 passed (1,201 assertions)** — 0 failed, 0 skipped.
  - Phase 8 Unit: `DividendIncomeServiceTest`, `RssNewsAdapterTest`.
  - Phase 8 Feature: `Phase8ModulesTest`, updated `MarketAndStockPagesTest`, `PreferencesAndLocalizationTest` (locale parity).
- `php artisan migrate:fresh --seed --force` on MySQL 8.0.30: succeeded cleanly across all seeders including `DemoNewsAndResearchSeeder` (`div_realized_all = 1200000.0000`, `div_projected_net = 7138202.5075`, `div_paying_holdings = 4`, `news_count = 12`, `research_count = 3`).
- Laravel HTTP Kernel smoke test (`/`, `/login`, `/dashboard`, `/dividends`, `/newsroom`, `/research-center`, `/stocks/idx/bbca`): HTTP `200`.

---

## 5. Known Issues & Limitations
- **Market holiday calendar**: Empty by default (`holidays_loaded = false`) until imported via `/admin/imports` (`market_holidays` CSV).
- **Intraday VWAP**: Not derived from daily EOD bars (explicitly disclosed on `PriceChart`).
- **Live broker sync**: `supports_live_sync = false` across all brokers; NADI uses manual entry and broker CSV statement imports rather than asking for brokerage passwords or OTPs.
- **CSP header**: Deferred to Phase 12 hardening.

---

## 6. Next Actionable Tasks — Phase 9 (Institutional Reports & Export Center)
1. Implement **Printable & Downloadable Reports Center** (`/reports`: Portfolio Valuation Statement, Performance & TWR/XIRR Summary, Performance Attribution Brief, Dividend Income & Tax Schedule, and Risk/Rebalancing Plan views).
2. Implement **Shared-Hosting Safe Exports** (clean browser print CSS / downloadable HTML/PDF-ready statements via pure PHP/React without requiring headless Chrome or heavy binary runtimes in cPanel production, alongside clean CSV exports with formula-injection sanitization).
