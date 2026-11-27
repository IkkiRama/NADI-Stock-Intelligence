# NADI Architecture

## Overview
NADI is a monolithic web application designed for conventional cPanel PHP/MySQL shared hosting without requiring Node.js, Python, Docker, Redis, or persistent queue/WebSocket daemons in production.

## Technology Stack
- **Backend**: Laravel 12.74 (PHP ^8.2, tested locally on PHP 8.3.7), `brick/math` (^0.14.8) for arbitrary-precision decimal arithmetic.
- **Frontend**: React 19 + TypeScript + Inertia.js v2 + Ziggy (`tightenco/ziggy`) + Tailwind CSS v4 + Radix UI / shadcn primitives + `lucide-react` + TradingView `lightweight-charts` v5 + `cmdk` + self-hosted `@fontsource-variable/inter`.
- **Database & Cache**: MySQL 8.0 (compatible with MySQL 5.7.8+ / MariaDB 10.3+). Sessions and cache use the `database` driver; queues run synchronously (`sync`) or via scheduled Laravel commands triggered by cPanel cron.
- **Planned framework upgrade**: Upgrade `laravel/framework` from `^12.0` to `^13.0` before Laravel 12 security EOL (~Feb 2027), scheduled in Phase 12 or earlier.

## Module Structure
- `app/Support/Math/Dec.php`: Centralized exact decimal operations (`add`, `sub`, `mul`, `div`, `pctChange`, `round`, `cmp`).
- `app/Domain/Instruments/`: `TickSize` rules and `GlobalSearchService` (bounded typo tolerance).
- `app/Domain/MarketData/`: `MarketDataProvider` contract, `DemoMarketDataProvider`, `SeededRandom`, `MarketClockService`, `RefreshLatestQuotes`, and `MarketOverviewService`.
- `app/Domain/Analytics/`: `PriceReturnService` (`1D`, `1W`, `1M`, `3M`, `6M`, `YTD`, `1Y`, 52W high/low, 20D avg volume).
- `app/Domain/Administration/`: `AuditLogger` for immutable admin action logs.

## Timezone & Storage Conventions
- Trading dates (`trade_date`) are stored as SQL `DATE` representing the exchange-local trading date.
- Application timestamps (`created_at`, `updated_at`, `refreshed_at`, `last_login_at`, `viewed_at`) are stored in UTC (`APP_TIMEZONE=UTC`) and formatted in the user's configured display timezone (default `Asia/Jakarta`).
- Precomputed `instrument_quotes_latest` and `index_quotes_latest` tables eliminate SQL window functions and keep dashboard queries fast on shared hosting.
