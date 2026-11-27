# NADI Financial Methodology

## Precision & Rounding
- All backend monetary, price, and return calculations use `App\Support\Math\Dec` backed by `brick/math` (`BigDecimal`).
- Database column precisions:
  - Prices & index levels (`open`, `high`, `low`, `close`, `adj_close`, `prev_close`, `change`, indicators): `DECIMAL(20, 6)`
  - Monetary values (`value_traded`, statement items, `market_cap`): `DECIMAL(24, 4)` (`market_cap_idr`: `DECIMAL(26, 4)`)
  - FX rates: `DECIMAL(20, 8)`
  - Ratios & returns (`change_pct`, `roe`, `roa`, `net_margin`, `dividend_yield`, `volatility_20d`): `DECIMAL(16, 8)` stored as decimal fractions
  - Quantitative scores (`overall_score`, component sub-scores): `DECIMAL(6, 2)` on a `0–100` scale
- Default rounding mode: `RoundingMode::HALF_UP`. Division by zero returns `null`.

## Fundamental Engine (`FundamentalCalculator`)
- **Point-in-Time Selection**: Only financial statements with `published_at <= asOfDate` are eligible, preventing look-ahead bias.
- **Extraordinary Items**: `adjusted_net_income = net_income - coalesce(extraordinary_items, 0)`.
- **Cross-Currency Conversion**: When `statement.currency !== instrument.currency`, per-share earnings/book value and dividends are converted using `FxConversionService` (direct, inverse, or IDR-triangulated) as of `asOfDate`. If no rate exists, price-based ratios return `null` with `missing_fx_rate`.
- **Negative Earnings**: When `eps <= 0`, `PER` and `dividend_payout_ratio` are `null`. YoY `earnings_growth_yoy` is `null` when prior-period adjusted earnings are `<= 0`.
- **Sector-Specific Rules (Financials)**: For banks/financial institutions (`sector === 'Financials'` or `is_financial_sector`), `DER` is computed as `total_liabilities / total_equity`, while `current_ratio` and `FCF` are intentionally omitted (`null`).

## Technical Indicators (`TechnicalIndicatorService`)
- **SMA(20/50/200)** & **EMA(20/50)** (EMA seeded with N-bar SMA, smoothing `2/(N+1)`).
- **RSI(14)** & **ATR(14)**: Wilder's exponential smoothing.
- **MACD(12, 26, 9)**: `EMA(12) - EMA(26)`, 9-period EMA signal, histogram `MACD - Signal`.
- **Bollinger Bands(20, 2.0)**: 20-bar SMA ± 2.0 population standard deviations.
- **OBV**: Cumulative signed volume from bar 0.
- **20D Volatility**: Sample standard deviation of trailing 20 daily simple returns × `sqrt(252)`.
- **EOD Chart Integrity**: Intraday VWAP and intraday bars are never fabricated from daily EOD bars.

## Quantitative Stock Scoring (`StockScoringService`)
- Configurable weights summing to 100% (default: Fundamentals 30%, Valuation 25%, Technical 20%, Risk 15%, Catalysts 10%).
- Missing components are excluded from the denominator while `data_coverage` transparently reports the percentage of total weight backed by available metrics.
- A high NADI score reflects historical/current quantitative attributes only and is never an automatic BUY recommendation.

## NADI Stock Safety (`StockSafetyService`)
- Requires >= 25 daily price bars to compute a `0–100` statistical risk score (`insufficient_data` otherwise, unless regulatory suspension/special monitoring applies).
- Evaluates 5D/20D unusual price deviations, volume spikes vs 20D baseline, annualized 20D volatility, FX-normalized IDR liquidity thickness, opening gaps, upper-wick/high-volume reversals, fundamental divergence, and issuer regulatory status flags (`is_suspended`, `has_special_monitoring`, `has_exchange_warning`, low free float, concentrated ownership).
- Triggers safeguards when `risk_score >= safeguard_score` (default 65) or regulatory suspension/special monitoring is active. Findings are strictly statistical/structural observations and never assert definitive price manipulation.

## Smart Entry & Exit and Position Sizing (`SmartEntryExitService`, `PositionSizingCalculator`)
- Evaluates `short_term`, `swing`, and `long_term` plans from OHLCV structure, SMA/EMA trends, RSI(14), ATR(14), and NADI score.
- Every price level (`support`, `resistance`, `entry_low`, `entry_high`, `confirmation_price`, `stop_loss`, `invalidation_price`, `target_1`, `target_2`) is rounded to valid exchange price fractions via `TickSize::roundToTick`.
- **No Forced BUY & Safeguard Suppression**: Emits `suppressed_by_safety` whenever `safeguard_triggered = true`, and emits `no_setup` whenever trend/RSI criteria or post-cost `net_rr >= 1.50` fail.
- **Lot-Aware Position Sizing**: Uses exact `Dec` (`RoundingMode::DOWN`) after brokerage buy/sell fees and slippage assumptions (`config/nadi.php` `trading_costs`), rounding down to whole exchange lot multiples and capping by available allocated capital.

## Multi-Broker Portfolio Accounting (`PortfolioAccountingService`, `PortfolioValuationService`)
- **Cash vs Returns Separation**: `cash_deposit` and `cash_withdrawal` update `cash_balance` and `net_contributions` without ever being counted as investment profit (`realized_pnl` or `unrealized_pnl`).
- **Buy Acquisition Cost**: `acq_cost = (quantity * price) + broker_fee + exchange_fee + tax_amount`. Increases weighted-average cost pool and opens a dated FIFO `PortfolioCostLot`.
- **Sell Cost Relief (Dual Method)**:
  - **Weighted Average**: Relieves `total_cost_avg * (sell_qty / held_qty)` (or exact full remaining pool on complete exit); `realized_pnl_avg += net_proceeds - cost_relieved`.
  - **FIFO**: Consumes oldest open cost lots chronologically; `realized_pnl_fifo += net_proceeds - sum(lot_cost_relieved)`.
  - Both methods satisfy the conservation invariant `realized_avg + unrealized_avg == realized_fifo + unrealized_fifo` at any valuation price.
- **Corporate Actions & Transfers**: `stock_split` / `reverse_split` (`ratio_numerator : ratio_denominator`) and `bonus_shares` adjust share counts and per-share unit costs while preserving total cost basis; `stock_transfer_out` / `stock_transfer_in` carry cost basis across brokerage accounts without triggering artificial realized gains.
- **Immutable Audit Trail**: Historical ledger rows are never silently deleted; corrections occur via `reverseTransaction`, creating an explicit `'reversal'` record referencing `reversal_of_id` and replaying the account ledger atomically.

## Portfolio Command Center: Allocation & Concentration (`PortfolioAnalyticsCommandCenterService`, `PortfolioHistoryService`)
- **Zero-Duplication Asset Allocation**: Equity dimensions (`by_stock`, `by_sector`, `by_industry`, `by_country`, `by_exchange`, `by_size_tier`, `by_safety`) aggregate exclusively from consolidated holdings (`summary.market_value`). Account-inclusive dimensions (`by_broker`, `by_currency`, `by_asset_class`) aggregate equity holdings plus converted cash balances to reconcile exactly to `summary.total_equity`.
- **Concentration & HHI**: Computes Top 3 / Top 5 / Top 10 weights, single-stock exposure, and the Herfindahl-Hirschman Index ($\text{HHI} = \sum w_i^2 \in [0, 10000]$) along with effective equal-weighted positions ($N_{\text{eff}} = 10000 / \text{HHI}$). Warnings compare exposures against configurable portfolio thresholds without claiming diversification guarantees superior returns.
- **Historical Curve Reconstruction**: `PortfolioHistoryService` replays ledger transactions chronologically against historical daily EOD prices and FX rates in bounded batch queries under both Weighted-Average and FIFO cost basis conventions.
