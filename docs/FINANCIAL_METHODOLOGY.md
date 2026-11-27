# NADI Financial Methodology

## Precision & Rounding
- All backend monetary, price, and return calculations use `App\Support\Math\Dec` backed by `brick/math` (`BigDecimal`).
- Database column precisions:
  - Prices & index levels (`open`, `high`, `low`, `close`, `adj_close`, `prev_close`, `change`): `DECIMAL(20, 6)`
  - Monetary values (`value_traded`, future cash/cost basis): `DECIMAL(24, 4)`
  - Ratios & returns (`change_pct`, future weights/metrics): `DECIMAL(16, 8)` stored as decimal fractions (e.g., `0.10000000` represents `+10.00%`)
  - Volumes: `UNSIGNED BIGINT`
- Default rounding mode: `RoundingMode::HALF_UP`.
- Division by zero returns `null` (never throws uncaught exceptions or fabricates `0`).

## Quote Change & Period Returns (`PriceReturnService`)
- **Daily Change**: `change = close_t - close_{t-1}`; `change_pct = (close_t - close_{t-1}) / close_{t-1}`. When only a single bar exists, `prev_close`, `change`, and `change_pct` are `null`.
- **Period Returns (`1W`, `1M`, `3M`, `6M`, `1Y`)**: Anchor date is computed by subtracting the calendar window from the latest available trading date; the anchor close is the **last recorded close on or before the anchor date**. If history does not reach the anchor date, `null` is returned (`—` in UI).
- **Year-to-Date (`YTD`)**: Uses the **last recorded close on or before December 31 of the prior calendar year**. Returns `null` if no prior-year closing bar exists.
- **52-Week High / Low**: Maximum `high` and minimum `low` over the trailing 252 trading bars.
- **20-Day Average Volume**: Arithmetic mean of the trailing 20 bars' volume, rounded to 2 decimal places (`HALF_UP`).

## EOD Chart Integrity
- Historical charts display daily EOD OHLCV bars (`instrument_prices_daily` / `index_values_daily`).
- Intraday bars are never synthesized from daily bars.
