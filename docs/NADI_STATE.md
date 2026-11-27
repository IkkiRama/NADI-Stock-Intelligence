# NADI Project State (`docs/NADI_STATE.md`)

**Last Updated**: 2026-11-27
**Project Path**: `D:\laragon\www\nadi`
**Current Phase**: Phase 3 Complete — Ready for **Phase 4 (Multi-Broker Portfolio Accounting)**

---

## 1. Completed Modules
### Phase 0 & Phase 1 (Foundation & Premium UI)
- Laravel 12.74 + Inertia v2 + Ziggy + React 19 + TypeScript + Tailwind CSS v4 monolith (`0be0930`).
- Authentication, `EnsureUserIsActive`, RBAC + audit-logged Admin Panel (`/admin`), ID/EN i18n, Workspace Preferences (`/settings/preferences`), Editorial Fintech design system, Command Palette (`Ctrl/Cmd+K`), Overview Dashboard (`/dashboard`), Global Markets (`/markets`, `/markets/indices/{code}`), Stock Explorer (`/stocks`), Watchlist, and PWA asset cache.

### Phase 2 (Market Intelligence)
- **FX & Importers**: `FxConversionService`, `CsvMarketImporter` + `CsvSanitizer`, `ConfigurableHttpEodProvider`, `/admin/imports`, `php artisan nadi:refresh-analytics` (`fee3db8`).
- **Quantitative Analytics**: `FundamentalCalculator` (point-in-time, cross-currency, Financials rules), `TechnicalIndicatorService`, `StockScoringService` (`/admin/scoring`).
- **Market Intelligence UI**: **Market Heatmap** (`/heatmap`, Apache ECharts v6), **Stock Screener** (`/screener`, presets + CSV export), upgraded **Stock Detail** (`/stocks/{exchange}/{symbol}`) and `PriceChart`.

### Phase 3 (Stock Safety, Trading Signals, Position Sizing & Alerts)
- **NADI Stock Safety (`StockSafetyService`)**:
  - Evaluates statistical anomaly rules (`price_surge_5d`, `price_surge_20d`, `volume_spike`, `high_volatility`, `thin_liquidity` via IDR FX conversion, `price_gap_anomaly`, `pump_reversal_pattern`, `fundamental_divergence`), baseline volatility, and `InstrumentRegulatoryStatus` (`is_suspended`, `has_special_monitoring`, `has_exchange_warning`, low `free_float_pct`, high `top_holder_pct`).
  - Returns `0–100` `risk_score`, category (`low`/`moderate`/`elevated`/`high`/`insufficient_data`), `safeguard_triggered`, `triggered_rules`, `data_confidence`, and `missing_factors` (honestly noting `broker_concentration` for Phase 10). Never asserts definitive price manipulation.
- **Smart Entry & Exit (`SmartEntryExitService`)**:
  - Generates `short_term`, `swing`, and `long_term` plans with tick-aligned (`TickSize::roundToTick`) entry zones, confirmation trigger, stop-loss, invalidation price, targets 1 & 2, 3-stage tranches, and gross/net R:R adjusted for exchange transaction fees & slippage (`config/nadi.php` `trading_costs`).
  - Automatically suppresses actionable BUY plans (`suppressed_by_safety`) when Stock Safety safeguards trigger, and returns `no_setup` whenever trend, momentum, or `net_rr >= 1.50` fails.
- **Position Sizing Calculator (`PositionSizingCalculator`)**:
  - Exact `Dec` (`RoundingMode::DOWN`) lot-aware sizing (`POST /entry-exit/position-size`) factoring board lots, capital caps, brokerage fees, and slippage.
- **UI Modules, Chart Overlays & Alerts**:
  - Active routes & navigation for **Opportunities** (`/opportunities`, hard-excluding safeguard-flagged stocks), **Stock Safety** (`/stock-safety`), **Smart Entry & Exit** (`/entry-exit`), and **Alerts & Calendar** (`/alerts-calendar`, user-scoped `UserAlert`/`AlertEvent` CRUD + `AlertEvaluator` + corporate action/holiday calendar).
  - Upgraded `PriceChart` (`createPriceLine` S/R/Entry/SL/TP overlays + `createSeriesMarkers` signal markers), `StockDetail` Phase 3 sections, `Screener` safety column & `exclude_flagged` filter, `Dashboard` live safety/signals/events widgets, and Admin **Safety Rules** (`/admin/safety-rules`, `can:manage-safety`).

---

## 2. Key Architectural Decisions
- **Framework**: Laravel 12.74 (`^12.0`, PHP `^8.2`) + Ziggy. Planned upgrade to Laravel 13 before Feb 2027 security EOL.
- **Shared Hosting Compatibility**: Monolithic Laravel + MySQL (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`), precompiled Vite assets (`npm.cmd run build`, no SSR), no Node/Python/Docker/Redis in production.
- **MySQL Query Compatibility**: No SQL window functions or CTEs; precomputed snapshot tables (`*_quotes_latest`, `instrument_fundamentals_latest`, `instrument_technicals_latest`, `instrument_scores`, `stock_safety_assessments`, `trading_signals`).
- **Decimal Arithmetic**: `brick/math` (`Dec` helper) across backend services + explicit Eloquent `decimal:N` casts for SQLite/MySQL parity.

---

## 3. Database Migration Status
All 12 migrations applied cleanly on MySQL 8.0.30 (`nadi`), including `2026_11_27_200001_create_phase3_safety_signals_alerts_tables`.
Verified MySQL demo seed counts: `1` safety config, `35` stock safety assessments (`1` safeguard triggered on demo `GOTO`), `105` latest trading signals across 3 horizons (`46` actionable/watchlist setups), and `2` upcoming demo corporate actions alongside 35 instruments, 8 indices, and 9,100 daily equity bars.

---

## 4. Verification & Test Results
- `npx.cmd tsc --noEmit`: 0 errors.
- `npm.cmd run build`: succeeded (`vite v6.1.1`, 2,641 modules transformed).
- `php artisan test`: **69 passed (708 assertions)** — 0 failed, 0 skipped (Duration: 118.78s).
  - Phase 3 Unit: `StockSafetyServiceTest`, `SmartEntryExitServiceTest`, `PositionSizingCalculatorTest`, `AlertEvaluatorTest`.
  - Phase 3 Feature: `Phase3ModulesTest`, `AdminSafetyRulesTest`, updated `MarketAndStockPagesTest`.
- `php artisan migrate:fresh --seed --force` on MySQL 8.0.30: succeeded (`safety_configs=1`, `safety_assessments=35`, `safeguard_count=1`, `signals=105`, `actionable_or_watch=46`).

---

## 5. Known Issues & Limitations
- **Market holiday calendar**: Empty by default (`holidays_loaded = false`) until imported via `/admin/imports` (`market_holidays` CSV).
- **Intraday VWAP**: Not derived from daily EOD bars (explicitly disclosed on `PriceChart`).
- **Lot size verification**: SGX/HKEX board lots remain flagged `lot_size_verified = false`.
- **Broker concentration**: Disclosed in `StockSafetyAssessment::missing_factors` until Phase 10 Broker Intelligence.
- **CSP header**: Deferred to Phase 12 hardening.

---

## 6. Next Actionable Tasks — Phase 4 (Multi-Broker Portfolio Accounting & Calculators)
1. Implement multi-broker portfolio & cash ledger schema (`brokers`, `portfolio_accounts`, `portfolios`, `portfolio_cash_transactions`, `portfolio_transactions`, `portfolio_holdings_snapshot`).
2. Implement exact `Dec` cost-basis engines (Weighted Average & FIFO lot matching), corporate action adjustments (stock split / cash dividend cash flows), realized/unrealized P&L, and multi-currency IDR conversion.
3. Build **My Portfolio** (`/portfolio`) and **Financial Calculators** (`/calculators`, brokerage fee, average price, break-even, currency conversion), and activate Portfolio Heatmap scope (`/heatmap?scope=portfolio`) & Stock Detail holding status card.
