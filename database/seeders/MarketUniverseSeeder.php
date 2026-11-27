<?php

namespace Database\Seeders;

use App\Models\Exchange;
use App\Models\Instrument;
use App\Models\StockIndex;
use Illuminate\Database\Seeder;

class MarketUniverseSeeder extends Seeder
{
    public function run(): void
    {
        $exchanges = Exchange::query()->get()->keyBy('code');

        $stocks = [
            // IDX (10)
            ['IDX', 'BBCA', 'Bank Central Asia Tbk', 'Financials', 'IDR', 'ID', 100, true, 9800, 45000000],
            ['IDX', 'BBRI', 'Bank Rakyat Indonesia (Persero) Tbk', 'Financials', 'IDR', 'ID', 100, true, 4600, 95000000],
            ['IDX', 'BMRI', 'Bank Mandiri (Persero) Tbk', 'Financials', 'IDR', 'ID', 100, true, 6400, 52000000],
            ['IDX', 'TLKM', 'Telkom Indonesia (Persero) Tbk', 'Communication Services', 'IDR', 'ID', 100, true, 2950, 75000000],
            ['IDX', 'ASII', 'Astra International Tbk', 'Industrials', 'IDR', 'ID', 100, true, 5100, 32000000],
            ['IDX', 'UNVR', 'Unilever Indonesia Tbk', 'Consumer Staples', 'IDR', 'ID', 100, true, 2250, 18000000],
            ['IDX', 'ICBP', 'Indofood CBP Sukses Makmur Tbk', 'Consumer Staples', 'IDR', 'ID', 100, true, 11400, 6500000],
            ['IDX', 'ADRO', 'Alamtri Resources Indonesia Tbk', 'Energy', 'IDR', 'ID', 100, true, 3600, 48000000],
            ['IDX', 'ANTM', 'Aneka Tambang Tbk', 'Materials', 'IDR', 'ID', 100, true, 1540, 58000000],
            ['IDX', 'GOTO', 'GoTo Gojek Tokopedia Tbk', 'Technology', 'IDR', 'ID', 100, true, 68, 1200000000],

            // US NASDAQ (5)
            ['NASDAQ', 'AAPL', 'Apple Inc.', 'Technology', 'USD', 'US', 1, true, 224.00, 48000000],
            ['NASDAQ', 'MSFT', 'Microsoft Corporation', 'Technology', 'USD', 'US', 1, true, 420.00, 21000000],
            ['NASDAQ', 'NVDA', 'NVIDIA Corporation', 'Technology', 'USD', 'US', 1, true, 134.00, 240000000],
            ['NASDAQ', 'AMZN', 'Amazon.com, Inc.', 'Consumer Discretionary', 'USD', 'US', 1, true, 198.00, 36000000],
            ['NASDAQ', 'GOOGL', 'Alphabet Inc.', 'Communication Services', 'USD', 'US', 1, true, 174.00, 26000000],

            // US NYSE (3)
            ['NYSE', 'JPM', 'JPMorgan Chase & Co.', 'Financials', 'USD', 'US', 1, true, 226.00, 9500000],
            ['NYSE', 'KO', 'The Coca-Cola Company', 'Consumer Staples', 'USD', 'US', 1, true, 66.00, 14000000],
            ['NYSE', 'XOM', 'Exxon Mobil Corporation', 'Energy', 'USD', 'US', 1, true, 118.00, 16000000],

            // SGX (5)
            ['SGX', 'D05', 'DBS Group Holdings Ltd', 'Financials', 'SGD', 'SG', 100, false, 38.50, 4200000],
            ['SGX', 'O39', 'Oversea-Chinese Banking Corporation Limited', 'Financials', 'SGD', 'SG', 100, false, 15.20, 5100000],
            ['SGX', 'U11', 'United Overseas Bank Limited', 'Financials', 'SGD', 'SG', 100, false, 32.80, 2900000],
            ['SGX', 'Z74', 'Singapore Telecommunications Limited', 'Communication Services', 'SGD', 'SG', 100, false, 3.12, 22000000],
            ['SGX', 'C6L', 'Singapore Airlines Limited', 'Industrials', 'SGD', 'SG', 100, false, 6.45, 5800000],

            // SSE (3)
            ['SSE', '600519', 'Kweichow Moutai Co., Ltd.', 'Consumer Staples', 'CNY', 'CN', 100, true, 1540.00, 2800000],
            ['SSE', '601318', 'Ping An Insurance (Group) Company of China', 'Financials', 'CNY', 'CN', 100, true, 51.20, 48000000],
            ['SSE', '600036', 'China Merchants Bank Co., Ltd.', 'Financials', 'CNY', 'CN', 100, true, 36.80, 52000000],

            // SZSE (3)
            ['SZSE', '000858', 'Wuliangye Yibin Co., Ltd.', 'Consumer Staples', 'CNY', 'CN', 100, true, 138.00, 16000000],
            ['SZSE', '300750', 'Contemporary Amperex Technology Co., Ltd.', 'Industrials', 'CNY', 'CN', 100, true, 246.00, 21000000],
            ['SZSE', '000333', 'Midea Group Co., Ltd.', 'Consumer Discretionary', 'CNY', 'CN', 100, true, 71.50, 24000000],

            // HKEX (6)
            ['HKEX', '0700', 'Tencent Holdings Limited', 'Communication Services', 'HKD', 'HK', 100, false, 412.00, 18000000],
            ['HKEX', '9988', 'Alibaba Group Holding Limited', 'Consumer Discretionary', 'HKD', 'HK', 100, false, 89.50, 45000000],
            ['HKEX', '0005', 'HSBC Holdings plc', 'Financials', 'HKD', 'HK', 400, false, 68.40, 16000000],
            ['HKEX', '1299', 'AIA Group Limited', 'Financials', 'HKD', 'HK', 200, false, 59.20, 28000000],
            ['HKEX', '0941', 'China Mobile Limited', 'Communication Services', 'HKD', 'HK', 500, false, 73.50, 15000000],
            ['HKEX', '3690', 'Meituan', 'Consumer Discretionary', 'HKD', 'HK', 100, false, 162.00, 26000000],
        ];

        foreach ($stocks as [$exCode, $symbol, $name, $sector, $currency, $country, $lotSize, $lotVerified, $basePrice, $baseVol]) {
            $exchange = $exchanges->get($exCode);
            if (! $exchange) {
                continue;
            }

            Instrument::query()->updateOrCreate(
                ['exchange_id' => $exchange->id, 'symbol' => $symbol],
                [
                    'name' => $name,
                    'type' => 'equity',
                    'currency' => $currency,
                    'sector' => $sector,
                    'industry' => null,
                    'country_code' => $country,
                    'lot_size' => $lotSize,
                    'is_active' => true,
                    'metadata' => [
                        'lot_size_verified' => $lotVerified,
                        'demo_base_price' => $basePrice,
                        'demo_base_volume' => $baseVol,
                    ],
                ],
            );
        }

        $indices = [
            ['IHSG', 'IDX Composite Index (IHSG)', 'ID', 'IDX', 'IDR', 'price'],
            ['LQ45', 'IDX LQ45 Index', 'ID', 'IDX', 'IDR', 'price'],
            ['SPX', 'S&P 500 Index', 'US', 'NYSE', 'USD', 'price'],
            ['NDX', 'Nasdaq-100 Index', 'US', 'NASDAQ', 'USD', 'price'],
            ['DJI', 'Dow Jones Industrial Average', 'US', 'NYSE', 'USD', 'price'],
            ['STI', 'Straits Times Index', 'SG', 'SGX', 'SGD', 'price'],
            ['CSI300', 'CSI 300 Index', 'CN', 'SSE', 'CNY', 'price'],
            ['HSI', 'Hang Seng Index', 'HK', 'HKEX', 'HKD', 'price'],
        ];

        foreach ($indices as [$code, $name, $country, $exCode, $currency, $type]) {
            StockIndex::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'country_code' => $country,
                    'exchange_id' => $exchanges->get($exCode)?->id,
                    'currency' => $currency,
                    'index_type' => $type,
                    'is_benchmark' => true,
                    'is_active' => true,
                ],
            );
        }
    }
}
