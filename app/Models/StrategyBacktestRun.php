<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyBacktestRun extends Model
{
    protected $fillable = [
        'user_id',
        'instrument_id',
        'name',
        'strategy_code',
        'range_code',
        'currency',
        'initial_capital',
        'parameters',
        'summary_metrics',
        'trades_count',
        'total_return_pct',
        'buy_hold_return_pct',
        'max_drawdown_pct',
        'sharpe_ratio',
        'win_rate_pct',
        'is_demo',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'initial_capital' => 'decimal:4',
            'parameters' => 'array',
            'summary_metrics' => 'array',
            'trades_count' => 'integer',
            'total_return_pct' => 'decimal:8',
            'buy_hold_return_pct' => 'decimal:8',
            'max_drawdown_pct' => 'decimal:8',
            'sharpe_ratio' => 'decimal:6',
            'win_rate_pct' => 'decimal:8',
            'is_demo' => 'boolean',
            'executed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
