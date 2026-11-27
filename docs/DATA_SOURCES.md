# NADI Data Sources & Market Rules

## Provider Adapter Architecture
Market data ingestion is abstracted behind `App\Domain\MarketData\Contracts\MarketDataProvider`, allowing each market and dataset to be switched without altering analytics or UI modules.

### Configured Data Sources (Phase 1)
1. **`demo` — NADI Demo Market Generator (`is_demo = true`, enabled)**
   - Implemented by `App\Domain\MarketData\Providers\DemoMarketDataProvider`.
   - Uses a deterministic xorshift32 PRNG (`SeededRandom`) seeded by `crc32(exchange.symbol)` or `crc32('INDEX:' . code)`.
   - Generates 260 weekday OHLCV bars per equity and index with tick-aligned prices.
   - Visibly labeled across all UI screens with a top Demo Mode banner and `Demo` provenance badges.
2. **`csv_manual` — Manual CSV / Statement Importer (`is_enabled = false`)**
   - Registered in `data_sources` as disabled until Phase 2 importer implementation.

### Candidate External Providers (Unverified / Requires Licensing Review)
Before connecting any live/delayed external feed in Phase 2+, verify commercial licensing, redistribution terms, rate limits, and EOD vs. delayed intraday entitlements:
- IDX / Regional Asia: official exchange data feeds, licensed regional vendors.
- US / Global: EODHD, Twelve Data, Alpha Vantage, Tiingo, Polygon.io, Financial Modeling Prep (evaluate free vs. paid tiers and commercial terms).

## Exchange Rules Requiring Periodic Verification
- **Tick sizes** (`app/Domain/Instruments/Support/TickSize.php`): IDX bands, HKEX spread table, SGX `<1` / `>=1`, and US/CN `0.01` rules are standard reference schedules and must be verified against current official exchange circulars before live order planning.
- **Lot sizes** (`instruments.lot_size` & `metadata.lot_size_verified`): SGX and HKEX board lot sizes vary by counter; unverified entries carry `lot_size_verified = false` and display a verification note on the Stock Detail page.
- **Market holidays** (`market_holidays`): Holiday dates are intentionally **not fabricated**. When no holidays are loaded for the current year, `MarketClockService` sets `holidays_loaded = false` and the UI displays *"Kalender libur belum diimpor"*.
