<?php

return [
    'version' => '0.6.0-phase6',

    'locales' => ['id', 'en'],

    'currencies' => ['IDR', 'USD', 'SGD', 'CNY', 'HKD'],

    'exchanges' => ['IDX', 'NYSE', 'NASDAQ', 'SGX', 'SSE', 'SZSE', 'HKEX'],

    'timeframes' => ['1M', '3M', '6M', 'YTD', '1Y'],

    'defaults' => [
        'locale' => 'id',
        'appearance' => 'system',
        'density' => 'comfortable',
        'analysis_mode' => 'simple',
        'currency' => 'IDR',
        'market' => 'IDX',
        'timeframe' => '1Y',
        'timezone' => 'Asia/Jakarta',
        'benchmark' => 'IHSG',
    ],

    'market_data' => [
        'default_provider' => env('NADI_MARKET_DATA_PROVIDER', 'demo'),
        'demo' => [
            'enabled' => (bool) env('NADI_DEMO_DATA', true),
            'history_weekdays' => 260,
        ],
    ],

    'dashboard_widgets' => [
        'market_indices',
        'market_clocks',
        'breadth_movers',
        'sector_performance',
        'watchlist_movements',
        'portfolio_summary',
        'stock_safety_warnings',
        'trading_signals',
        'curated_news',
        'upcoming_events',
    ],

    'trading_costs' => [
        'IDX' => ['buy_fee_pct' => '0.0015', 'sell_fee_pct' => '0.0025', 'slippage_pct' => '0.0010'],
        'NYSE' => ['buy_fee_pct' => '0.0005', 'sell_fee_pct' => '0.0005', 'slippage_pct' => '0.0005'],
        'NASDAQ' => ['buy_fee_pct' => '0.0005', 'sell_fee_pct' => '0.0005', 'slippage_pct' => '0.0005'],
        'SGX' => ['buy_fee_pct' => '0.0015', 'sell_fee_pct' => '0.0015', 'slippage_pct' => '0.0010'],
        'SSE' => ['buy_fee_pct' => '0.0015', 'sell_fee_pct' => '0.0020', 'slippage_pct' => '0.0010'],
        'SZSE' => ['buy_fee_pct' => '0.0015', 'sell_fee_pct' => '0.0020', 'slippage_pct' => '0.0010'],
        'HKEX' => ['buy_fee_pct' => '0.0018', 'sell_fee_pct' => '0.0018', 'slippage_pct' => '0.0010'],
        'DEFAULT' => ['buy_fee_pct' => '0.0015', 'sell_fee_pct' => '0.0015', 'slippage_pct' => '0.0010'],
    ],
];
