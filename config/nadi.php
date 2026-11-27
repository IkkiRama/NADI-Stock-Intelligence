<?php

return [
    'version' => '0.8.0-phase8',

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

    // Annual reference risk-free rates (decimal fractions); configurable by user in Risk Studio
    'risk_free_rates' => [
        'IDR' => '0.0600',
        'USD' => '0.0450',
        'SGD' => '0.0320',
        'CNY' => '0.0200',
        'HKD' => '0.0400',
        'DEFAULT' => '0.0450',
    ],

    // Reference statutory dividend withholding rates (decimal fractions; user-overridable)
    'dividend_withholding_tax' => [
        'ID' => '0.10',
        'US' => '0.15',
        'SG' => '0.00',
        'CN' => '0.10',
        'HK' => '0.00',
        'DEFAULT' => '0.10',
    ],
];
