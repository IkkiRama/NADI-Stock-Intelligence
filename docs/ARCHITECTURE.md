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
- `app/Domain/Portfolio/`: `PortfolioValuationService` (cross-currency IDR/multi-currency valuation & stock-level holding status).
- `app/Domain/Notifications/`: `AlertEvaluator` (idempotent daily price, safety, and signal alerts).
- `app/Domain/Administration/`: `AuditLogger` for immutable admin action logs.

## Timezone & Storage Conventions
- Trading dates (`trade_date`, `ex_date`, `published_at`) are stored as SQL `DATE` representing exchange-local dates.
- Application timestamps (`created_at`, `updated_at`, `refreshed_at`, `calculated_at`, `last_login_at`, `viewed_at`) are stored in UTC (`APP_TIMEZONE=UTC`) and formatted in the user's configured display timezone (default `Asia/Jakarta`).
- Precomputed snapshot tables (`instrument_quotes_latest`, `index_quotes_latest`, `instrument_fundamentals_latest`, `instrument_technicals_latest`, `instrument_scores`, `stock_safety_assessments`, `trading_signals`, `portfolio_holdings`, `portfolio_cost_lots`) eliminate SQL window functions and heavy per-request computations on shared hosting.
