# NADI Data Sources & Market Rules

## Provider Adapter Architecture
Market data ingestion is abstracted behind `App\Domain\MarketData\Contracts\MarketDataProvider`, allowing each market and dataset to be switched without altering analytics or UI modules.

### Configured Data Sources (Phase 2)
1. **`demo` — NADI Demo Market Generator (`is_demo = true`, enabled)**
   - Implemented by `App\Domain\MarketData\Providers\DemoMarketDataProvider` and `Database\Seeders\DemoMarketIntelligenceSeeder`.
   - Uses deterministic xorshift32 PRNG (`SeededRandom`) seeded by exchange/symbol keys.
   - Generates 260 weekday OHLCV bars, FY 2024–2025 demo financial statements (including negative-earnings `GOTO` and USD-reporting `ADRO` fixtures), demo FX rates, and corporate actions.
   - Visibly labeled across all UI screens with a top Demo Mode banner and `Demo` provenance badges.
2. **`csv_manual` — Manual CSV / Statement Importer (`is_enabled = true`, `is_demo = false`)**
   - Implemented by `App\Domain\MarketData\Services\CsvMarketImporter` with `CsvSanitizer` spreadsheet formula-injection protection.
   - Supports idempotent chunked imports for `prices_daily`, `market_holidays`, `financial_statements`, and `corporate_actions` via `/admin/imports`.
3. **`ConfigurableHttpEodProvider` (Adapter Stub)**
   - Configured via `NADI_EOD_API_URL` and `NADI_EOD_API_KEY` in `config/services.php`. Throws an explicit error when unconfigured and never fabricates live feeds.

### Candidate External Providers (Unverified / Requires Licensing Review)
Before connecting any live/delayed external feed, verify commercial licensing, redistribution terms, rate limits, and EOD vs. delayed intraday entitlements:
- IDX / Regional Asia: official exchange data feeds, licensed regional vendors.
- US / Global: EODHD, Twelve Data, Alpha Vantage, Tiingo, Polygon.io, Financial Modeling Prep (evaluate free vs. paid tiers and commercial terms).

## Exchange Rules Requiring Periodic Verification
- **Tick sizes** (`app/Domain/Instruments/Support/TickSize.php`): IDX bands, HKEX spread table, SGX `<1` / `>=1`, and US/CN `0.01` rules are standard reference schedules and must be verified against current official exchange circulars before live order planning.
- **Lot sizes** (`instruments.lot_size` & `metadata.lot_size_verified`): SGX and HKEX board lot sizes vary by counter; unverified entries carry `lot_size_verified = false` and display a verification note on the Stock Detail page.
- **Market holidays** (`market_holidays`): Holiday dates are intentionally **not fabricated**. Import official exchange calendars via `/admin/imports` (`market_holidays` CSV) to enable holiday detection (`holidays_loaded = true`).
