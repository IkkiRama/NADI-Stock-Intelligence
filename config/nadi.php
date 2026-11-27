<?php

return [
    'version' => '0.2.0-phase2',

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
];
