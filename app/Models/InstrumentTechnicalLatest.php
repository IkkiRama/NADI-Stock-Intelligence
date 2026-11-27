<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentTechnicalLatest extends Model
{
    protected $table = 'instrument_technicals_latest';

    protected $primaryKey = 'instrument_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'instrument_id',
        'trade_date',
        'sma_20',
        'sma_50',
        'sma_200',
        'ema_20',
        'ema_50',
        'macd_line',
        'macd_signal',
        'macd_hist',
        'bb_upper',
        'bb_middle',
        'bb_lower',
        'atr_14',
        'rsi_14',
        'obv',
        'volatility_20d',
        'return_1w',
        'return_1m',
        'return_3m',
        'return_6m',
        'return_1y',
        'relative_strength_1m',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'sma_20' => 'decimal:6',
            'sma_50' => 'decimal:6',
            'sma_200' => 'decimal:6',
            'ema_20' => 'decimal:6',
            'ema_50' => 'decimal:6',
            'macd_line' => 'decimal:6',
            'macd_signal' => 'decimal:6',
            'macd_hist' => 'decimal:6',
            'bb_upper' => 'decimal:6',
            'bb_middle' => 'decimal:6',
            'bb_lower' => 'decimal:6',
            'atr_14' => 'decimal:6',
            'rsi_14' => 'decimal:4',
            'obv' => 'integer',
            'volatility_20d' => 'decimal:8',
            'return_1w' => 'decimal:8',
            'return_1m' => 'decimal:8',
            'return_3m' => 'decimal:8',
            'return_6m' => 'decimal:8',
            'return_1y' => 'decimal:8',
            'relative_strength_1m' => 'decimal:8',
            'calculated_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
