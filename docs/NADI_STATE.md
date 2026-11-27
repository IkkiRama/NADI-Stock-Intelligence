# NADI Project State (`docs/NADI_STATE.md`)

**Last Updated**: 2026-11-27
**Project Path**: `D:\laragon\www\nadi`
**Current Phase**: Phase 6 Complete — Ready for **Phase 7 (Risk, Drawdown & Rebalancing Studio)**

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
- `npm.cmd run build`: succeeded (`vite v6.1.1`, 2,648 modules transformed).
- `php artisan test`: **82 passed (971 assertions)** — 0 failed, 0 skipped (Duration: 132.02s).
  - Phase 6 Unit: `TwrAndXirrCalculatorTest`, `PerformanceAttributionAndBenchmarkTest`.
  - Phase 6 Feature: `PortfolioAnalyticsPageTest`, updated `MarketAndStockPagesTest`, `PreferencesAndLocalizationTest` (locale parity).
- `php artisan migrate:fresh --seed --force` on MySQL 8.0.30: succeeded cleanly across all seeders (`days = 44`, `twr_total = 0.02951214`, `xirr_status = converged`, `xirr_ann = 0.19713680`, `attr_gain = 11443321.5000`, `te = 0.26382464`, `ir = 1.6563`).
- HTTP smoke test (`/` and `/login`): HTTP `200`.

---

## 5. Known Issues & Limitations
- **Market holiday calendar**: Empty by default (`holidays_loaded = false`) until imported via `/admin/imports` (`market_holidays` CSV).
- **Intraday VWAP**: Not derived from daily EOD bars (explicitly disclosed on `PriceChart`).
- **Live broker sync**: `supports_live_sync = false` across all brokers; NADI uses manual entry and broker CSV statement imports rather than asking for brokerage passwords or OTPs.
- **CSP header**: Deferred to Phase 12 hardening.

---

## 6. Next Actionable Tasks — Phase 7 (Risk, Drawdown & Rebalancing Studio)
1. Implement **Portfolio Risk & Drawdown Analytics** (historical max drawdown & recovery timeline, Value at Risk / historical volatility, beta vs benchmark, downside deviation & Sharpe/Sortino ratios with risk-free rate input).
2. Implement **Asset Correlation & Factor / Currency Exposure Analysis** (rolling correlation heatmap across holdings and multi-currency exposure sensitivity).
3. Implement **Target Allocation & Rebalancing Engine** (`/risk-rebalancing`: target weight models, drift detection, lot-aware suggested buy/sell rebalance orders accounting for fees, cash buffer, and tax/safety constraints).
