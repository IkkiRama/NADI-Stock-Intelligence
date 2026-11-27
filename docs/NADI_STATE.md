# NADI Project State (`docs/NADI_STATE.md`)

**Last Updated**: 2026-11-27
**Project Path**: `D:\laragon\www\nadi`
**Current Phase**: Phase 11 Complete — Ready for **Phase 12 (Production Security Hardening, Shared-Hosting Audit & v1.0.0 Release)**

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

### Phase 9 (Institutional Reports & Export Center)
- **Institutional Report Assembly (`PortfolioReportBuilderService`, `/reports`)**:
  - Composes 5 report packages directly from existing verified domain services (`executive_statement`, `performance_brief`, `dividend_tax_schedule`, `risk_rebalancing_plan`, `tax_realized_ledger`) without duplicating financial formulas, plus `buildLedgerRegister()` for transaction summaries and currency-aligned reporting filters.
- **Shared-Hosting Safe Exports (`ReportsController`, `ReportCsvExporter`, `ReportExport`)**:
  - Printable standalone A4 `@media print` HTML statements (`GET /reports/print`, `resources/views/reports/print.blade.php`) with `DEMO DATA NOTICE` and methodology/disclaimer footers, UTF-8 BOM + `CsvSanitizer::escapeCell` formula-injection-safe CSV exports (`GET /reports/export-csv`), and JSON downloads (`GET /reports/export-json`), logged per user in `report_exports`.
  - Activated `/reports` across `navigation.ts`, `GlobalSearchService`, Portfolio header quick-link, and `/segera/reports` redirect.

### Phase 10 (Broker Intelligence & Transaction Analytics)
- **EOD Broker Summary & Flow Engine (`BrokerIntelligenceService`, `BrokerSummaryDaily`, `DemoBrokerSummarySeeder`, `CsvMarketImporter`, `/broker-intelligence`)**:
  - Aggregates `broker_summaries_daily` across `1D`, `5D`, and `20D` windows with exact `Dec` math, CR3/CR5 concentration ratios, Foreign Institutional / Local Institutional / Retail net flows, Top Net Buyers & Sellers rankings, market-wide flow movers, ECharts daily flow bar chart (`BrokerFlowChart`), Stock Detail (`/stocks/{exchange}/{symbol}`) compact flow card, and UTF-8 BOM formula-safe CSV exports (`/broker-intelligence/export-csv`).
  - Supports admin CSV ingestion for `broker_summaries_daily` (`POST /admin/imports/csv`) with integer/decimal validation and idempotent upserts.
- **Official Broker Directory & Personal Fee Drag Analytics (`BrokerSeeder`, `BrokerIntelligenceController`)**:
  - Enriched `brokers` directory (`16` brokers including `MIRAE`, `UBS`, `MAYBANK`, `JPMORGAN`, `CLSA`, `MACQUARIE`, `BCASEK`) with `broker_category`, IDX member codes, typical buy/sell fee schedules, CSV adapters, and `supports_live_sync = false`.
  - Computes user-isolated per-account execution counts, traded value, broker/exchange/tax fees, and effective cost in `%` and `bps` (`buildPersonalBrokerAnalytics`), paired with explicit non-fabricated Level-2 Market Depth disclosure (`marketDepthStatus.licensed = false`).

### Phase 11 (Strategy Lab & Historical Backtesting Engine)
- **Deterministic Daily-Bar Backtest Engine (`StrategyBacktestService`, `StrategyLabController`, `/strategy-lab`)**:
  - Evaluates 4 rule templates (`sma_crossover`, `rsi_mean_reversion`, `macd_momentum`, `donchian_breakout`) across `6M`, `1Y`, and `ALL` windows with strict **zero look-ahead bias** (signals at bar $t$ close execute strictly at bar $t+1$ `open`), pessimistic intrabar Stop-Loss-before-Take-Profit tie-breaking, `TickSize::roundToTick`, whole exchange `lot_size` (`RoundingMode::DOWN`), buy/sell fees + slippage, Buy-and-Hold comparison, `< 30D` CAGR guard, `>= 20`-session Sharpe/Sortino guard, and 70%/30% In-Sample vs Out-of-Sample transparency.
  - Supports user-isolated snapshot persistence (`StrategyBacktestRun` in `strategy_backtest_runs`), ECharts Equity vs Buy & Hold / Drawdown visualization (`StrategyEquityChart`), UTF-8 BOM formula-safe CSV exports (`/strategy-lab/export-csv`), and completes activation of all modules in `config/nadi_modules.php`.

---

## 2. Key Architectural Decisions
- **Framework**: Laravel 12.74 (`^12.0`, PHP `^8.2`) + Ziggy. Planned upgrade to Laravel 13 before Feb 2027 security EOL.
- **Shared Hosting Compatibility**: Monolithic Laravel + MySQL (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`), precompiled Vite assets (`npm.cmd run build`, no SSR), no Node/Python/Docker/Redis in production.
- **MySQL Query Compatibility**: No SQL window functions or CTEs; precomputed snapshot tables + bounded batch replay in PHP (`Dec`).
- **Decimal Arithmetic**: `brick/math` (`Dec` helper) across all accounting & portfolio analytics services + explicit Eloquent `decimal:N` casts.

---

## 3. Database Migration Status
All 19 migrations applied cleanly on MySQL 8.0.30 (`nadi`), including `2026_11_27_900001_create_strategy_backtest_runs_table`.

---

## 4. Verification & Test Results
- `npx.cmd tsc --noEmit`: 0 errors.
- `npm.cmd run build`: succeeded (`vite v6.1.1`, 2,658 modules transformed).
- `php artisan test`: **107 passed (1,555 assertions)** — 0 failed, 0 skipped.
  - Phase 11 Unit: `StrategyBacktestServiceTest` (zero look-ahead next-bar open execution, IDX tick & whole-lot rounding, same-bar pessimistic SL-before-TP precedence, formula-safe CSV export).
  - Phase 11 Feature: `StrategyLabPageTest` (all 4 strategy templates, CSV export, user-isolated snapshot save/delete/403), updated `MarketAndStockPagesTest`, `PreferencesAndLocalizationTest` (67 matching `strategy_lab.*` keys in `id.json` / `en.json`).
- `php artisan migrate:fresh --seed --force` on MySQL 8.0.30 (`nadi`): succeeded cleanly across all 19 migrations and all seeders.
- Synchronous Laravel HTTP Kernel verification on MySQL: `/strategy-lab`, `/strategy-lab?symbol=BBCA&strategy=rsi_mean_reversion&range=1Y`, `/strategy-lab?symbol=MSFT&strategy=macd_momentum&range=1Y`, and `/strategy-lab/export-csv?symbol=BBCA&strategy=sma_crossover&range=1Y` (`HTTP 200`, UTF-8 BOM, `739` bytes).

---

## 5. Known Issues & Limitations
- **Market holiday calendar**: Empty by default (`holidays_loaded = false`) until imported via `/admin/imports` (`market_holidays` CSV).
- **Intraday VWAP**: Not derived from daily EOD bars (explicitly disclosed on `PriceChart`).
- **Live broker sync**: `supports_live_sync = false` across all brokers; NADI uses manual entry and broker CSV statement imports rather than asking for brokerage passwords or OTPs.
- **CSP header**: Deferred to Phase 12 hardening.

---

## 6. Next Actionable Tasks — Phase 12 (Production Security Hardening, Audit & v1.0.0 Release)
1. Enable strict Content-Security-Policy (CSP) headers in `SecurityHeaders` middleware compatible with Vite bundles, ECharts canvas, TradingView Lightweight Charts, and Ziggy routes.
2. Perform full shared-hosting production readiness audit (`config/nadi.php` `1.0.0`, `APP_DEBUG=false` & route/config cache verification, `DEPLOYMENT.md` final verification, end-to-end smoke check, and release tag/commit).
