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

## Advanced Performance & Benchmark Center (`TwrCalculator`, `XirrCalculator`, `PerformanceAttributionService`, `BenchmarkComparisonService`)
- **Time-Weighted Return (TWR)**: Geometrically chains daily subperiod returns $1 + \text{TWR} = \prod_t (1 + r_t)$ using a consistent flow-timing convention (external inflows `cash_deposit` / external `stock_transfer_in` enter at start-of-day $B_t = V_{t-1} + \text{inflow}_t$; external outflows `cash_withdrawal` / external `stock_transfer_out` exit at end-of-day $E_t = V_t - \text{outflow}_t$). Subperiods shorter than 30 calendar days omit annualized extrapolation (`twr_annualized = null`).
- **Money-Weighted Return (XIRR & Modified Dietz)**: Evaluates investor cash flows (initial window capital and deposits negative; withdrawals and terminal equity positive) by solving $\sum_k C_k (1 + r)^{-(d_k - d_0)/365} = 0$ via safeguarded Newton-Raphson with bounded grid-bracket bisection fallback on `[-0.9999, 100.0]`. Explicitly returns `insufficient_flows`, `no_sign_change`, or `non_convergent` with `xirr_annualized = null` rather than fabricating returns.
- **Performance Attribution**: Decomposes net investment gain in `reporting_currency` across individual stocks, sectors, countries, and brokerage accounts as well as Return Drivers (`local_price_effect_rep`, currency translation `fx_effect_rep`, net dividends, transaction fees/taxes, and cash FX/adjustments), normalizing by Modified Dietz average invested capital $K_{\text{MD}}$ so component contributions sum exactly to the Modified Dietz period return.
- **Benchmark Comparison**: Aligns portfolio TWR curves (base `100`) against `IHSG`, `LQ45`, `SPX`, `NDX`, `DJI`, `STI`, `CSI300`, and `HSI` with explicit `reporting_aligned` (daily FX conversion via `FxRateDaily`) vs `native_index` modes, issuing a visible currency-mismatch warning whenever an unadjusted foreign index is compared against a different reporting currency and disclosing `index_type` (`price` vs `total_return`). Annualized Tracking Error ($\sigma_a \sqrt{252}$) and Information Ratio are strictly gated on `observations >= 20`.

## Risk, Drawdown, Correlation & Lot-Aware Rebalancing (`PortfolioRiskAnalyticsService`, `HoldingCorrelationAndExposureService`, `PortfolioRebalancingService`)
- **Underwater Drawdown & Episodes**: Derived strictly from cumulative TWR wealth $W_t = 1 + \text{TWR}_t$ ($DD_t = W_t / \max_{s \le t} W_s - 1$) so deposits/withdrawals never create artificial drawdowns; records distinct peak-trough-recovery episodes (`recovered` vs `underwater`).
- **Volatility, Downside Risk & Ratios**: Require `observations >= 20`. Daily risk-free rate $r_{f,d} = (1 + R_f)^{1/252} - 1$. Annualized Volatility $\sigma_d \sqrt{252}$, Downside Deviation $\sqrt{\frac{1}{N} \sum \min(0, r_t - r_{f,d})^2} \sqrt{252}$, Historical 95% Daily VaR & CVaR, Sharpe, Sortino, Calmar, and Benchmark $\beta = \text{Cov}(r_p, r_b)/\text{Var}(r_b)$, $R^2$, Upside & Downside Capture.
- **Pairwise Correlation & FX Stress**: Batch-computes Pearson daily return correlations across up to top 12 holdings in `reporting_currency` (requiring `>= 20` overlapping sessions per pair) and deterministic `±5%` / `±10%` FX shock sensitivity across non-base currency equity + cash balances.
- **Two-Pass Lot-Aware Rebalancing Simulator**: Never executes brokerage orders directly. Evaluates drift relative to `drift_band_pct`, rounds prices via `TickSize::roundToTick`, executes Pass 1 overweight SELLs in whole exchange board lots (`RoundingMode::DOWN`), blocks BUY recommendations on Stock Safety `safeguard_triggered` stocks when enabled, and Pass 2 scales eligible BUYs across remaining spendable cash above `cash_buffer_pct` (`post_cash_rep >= 0`) after brokerage fee & slippage assumptions.

## Dividends & Passive Income, Newsroom & Research Provenance (`DividendIncomeService`, `RssNewsAdapter`, `ResearchCenterService`)
- **Realized vs Projected Dividends**: Historical income sums only posted, non-reversed `dividend_receipt` minus `dividend_tax` ledger rows converted at transaction-date FX. Forward 12M estimates prioritize trailing 12M `corporate_actions` (`cash_dividend`) distributions (or upcoming declared dividends / fundamental `dividend_yield`), explicitly tagged as `Estimate` after statutory or user-configured withholding tax rates.
- **Yield on Cost vs Market Value**: `yield_on_cost = projected_annual_gross_rep / total_cost_reporting` while `yield_on_mv = projected_annual_gross_rep / market_value_reporting`, accompanied by neutral `payout_ratio > 85%` flags.
- **Newsroom & Research Integrity**: Curated/demo news briefings carry `is_demo = true`; external RSS/Atom ingestion enforces HTTPS-only non-private-IP SSRF checks (`RssNewsAdapter`). Implied upside on personal `ResearchNote` records is computed only when `target_currency === instrument.currency`.
