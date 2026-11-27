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
