# NADI Architecture

## Overview
NADI is a monolithic web application designed for conventional cPanel PHP/MySQL shared hosting without requiring Node.js, Python, Docker, Redis, or persistent queue/WebSocket daemons in production.

## Technology Stack
- **Backend**: Laravel 12.74 (PHP ^8.2, tested locally on PHP 8.3.7), `brick/math` (^0.14.8) for arbitrary-precision decimal arithmetic.
- **Frontend**: React 19 + TypeScript + Inertia.js v2 + Ziggy (`tightenco/ziggy`) + Tailwind CSS v4 + Radix UI / shadcn primitives + `lucide-react` + TradingView `lightweight-charts` v5 + Apache `echarts` v6 (tree-shaken treemap) + `cmdk` + self-hosted `@fontsource-variable/inter`.
- **Database & Cache**: MySQL 8.0 (compatible with MySQL 5.7.8+ / MariaDB 10.3+). Sessions and cache use the `database` driver; queues run synchronously (`sync`) or via scheduled Laravel commands triggered by cPanel cron.
- **Planned framework upgrade**: Upgrade `laravel/framework` from `^12.0` to `^13.0` before Laravel 12 security EOL (~Feb 2027), scheduled in Phase 12 or earlier.

## Module Structure
- `app/Support/Math/Dec.php`: Centralized exact decimal operations (`add`, `sub`, `mul`, `div`, `pctChange`, `round`, `cmp`).
- `app/Domain/Instruments/`: `TickSize` rules and `GlobalSearchService` (bounded typo tolerance).
- `app/Domain/MarketData/`: `MarketDataProvider` contract, `DemoMarketDataProvider`, `ConfigurableHttpEodProvider`, `SeededRandom`, `MarketClockService`, `FxConversionService`, `CsvSanitizer`, `CsvMarketImporter`, `RefreshLatestQuotes`, `RefreshMarketIntelligence`, and `MarketOverviewService`.
- `app/Domain/Analytics/`: `PriceReturnService`, `FundamentalCalculator` (point-in-time, cross-currency, sector-aware), `TechnicalIndicatorService` (SMA, EMA, Wilder RSI, MACD, Bollinger, ATR, OBV, 20D volatility), and `StockScoringService` (0–100 configurable weights).
- `app/Domain/StockSafety/`: `StockSafetyService` (statistical anomaly rules, liquidity check, fundamental divergence, regulatory flags, safeguard scoring).
- `app/Domain/TradingSignals/`: `SmartEntryExitService` (horizon-specific tick-aligned setups, safeguard suppression, non-forced stances) and `PositionSizingCalculator` (exact `Dec` lot-aware sizing with fees & slippage).
- `app/Domain/PortfolioAccounting/`: `PortfolioAccountingService` (exact `Dec` Weighted-Average & FIFO cost basis, cash ledger, corporate actions, transfers, immutable reversals), `BrokerCsvImporter` (Stockbit, Ajaib, IPOT, BIONS, MOST, NADI Standard with SHA-256 dedupe), and `PortfolioReconciliationService`.
- `app/Domain/Portfolio/`: `PortfolioValuationService` (cross-currency IDR/multi-currency valuation, multi-account comparison & stock-level holding status), `PortfolioAnalyticsCommandCenterService` (multi-dimensional allocation & HHI concentration engine), and `PortfolioHistoryService` (batch EOD historical cost basis vs market value equity curve).
- `app/Domain/Performance/`: `PortfolioNavSeriesService` (chronological inception-to-date NAV & external flow series), `TwrCalculator` (subperiod chained Time-Weighted Return), `XirrCalculator` (Money-Weighted XIRR + Modified Dietz with safeguarded Newton/bisection solver), `PerformanceAttributionService` (Stock, Sector, Country, Broker & FX/Price/Dividend/Fee decomposition), and `BenchmarkComparisonService` (FX-aligned & native index curves, Excess Return, Tracking Error, Information Ratio).
- `app/Domain/Risk/`: `PortfolioRiskAnalyticsService` (TWR underwater drawdown episodes, annualized volatility, downside deviation, Sharpe, Sortino, Calmar, Historical 95% VaR/CVaR, Benchmark Beta & Capture Ratios), `HoldingCorrelationAndExposureService` (pairwise Pearson return correlation matrix & FX shock stress sensitivity), and `PortfolioRebalancingService` (two-pass lot-aware, tick-rounded, fee-aware, safety-guarded rebalancing simulator).
- `app/Domain/Dividends/`: `DividendIncomeService` (realized dividend ledger analytics, projected 12M forward gross/net estimates, withholding tax rates, yield on market value vs yield on cost, and payout sustainability alerts).
- `app/Domain/Newsroom/`: `NewsroomService` (portfolio/watchlist/global briefing feeds and ticker tagging) and `RssNewsAdapter` (offline-testable PHP `SimpleXML` RSS/Atom parser with SSRF HTTPS-only URL guard, plus `nadi:sync-news` scheduled hourly command).
- `app/Domain/Research/`: `ResearchCenterService` (user-isolated structured investment theses, price-target upside computation, catalysts/risks/invalidation tracking, and Search integration).
- `app/Domain/Reports/`: `PortfolioReportBuilderService` (assembles 5 institutional report types from valuation, performance, dividend, risk/rebalancing, and transaction ledger services) and `ReportCsvExporter` (UTF-8 BOM + `CsvSanitizer::escapeCell` formula-safe streaming CSV exporter), paired with shared-hosting-safe pure Blade `@media print` HTML statements (`/reports/print`) and JSON downloads (`/reports/export-json`) logged in `report_exports`.
- `app/Domain/BrokerIntelligence/`: `BrokerIntelligenceService` (EOD broker summary accumulation/distribution analysis, CR3/CR5 concentration & foreign/institutional/retail flow decomposition, official broker directory metadata, user-isolated personal brokerage fee drag in `%` and `bps`, licensed Level-2 market depth honesty disclosure, and sanitized flow CSV export), paired with `CsvMarketImporter` support for `broker_summaries_daily`.
- `app/Domain/StrategyLab/`: `StrategyBacktestService` (deterministic daily-bar rule backtester across SMA Crossover, RSI Mean Reversion, MACD Histogram Momentum, and Donchian Breakout with strict `t+1` next-bar open execution, pessimistic same-bar SL-before-TP tie-break, `TickSize` rounding, `RoundingMode::DOWN` whole-lot sizing, Buy-and-Hold comparison, 70%/30% In/Out-of-Sample split, saved runs in `strategy_backtest_runs`, and UTF-8 BOM CSV export).
- `app/Domain/Notifications/`: `AlertEvaluator` (idempotent daily price, safety, and signal alerts).
- `app/Domain/Administration/`: `AuditLogger` for immutable admin action logs, alongside `SecurityHeaders` middleware (per-request Vite/Ziggy CSP nonces + HSTS) and `php artisan nadi:doctor` shared-hosting diagnostic command.

## Timezone & Storage Conventions
- Trading dates (`trade_date`, `ex_date`, `published_at`) are stored as SQL `DATE` representing exchange-local dates.
- Application timestamps (`created_at`, `updated_at`, `refreshed_at`, `calculated_at`, `last_login_at`, `viewed_at`) are stored in UTC (`APP_TIMEZONE=UTC`) and formatted in the user's configured display timezone (default `Asia/Jakarta`).
- Precomputed snapshot tables (`instrument_quotes_latest`, `index_quotes_latest`, `instrument_fundamentals_latest`, `instrument_technicals_latest`, `instrument_scores`, `stock_safety_assessments`, `trading_signals`, `portfolio_holdings`, `portfolio_cost_lots`) eliminate SQL window functions and heavy per-request computations on shared hosting.
