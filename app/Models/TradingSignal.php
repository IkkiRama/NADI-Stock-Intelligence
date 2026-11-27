<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingSignal extends Model
{
    protected $fillable = [
        'instrument_id',
        'horizon',
        'signal_date',
        'is_latest',
        'stance',
        'entry_low',
        'entry_high',
        'confirmation_price',
        'stop_loss',
        'invalidation_price',
        'target_1',
        'target_2',
        'trailing_stop_atr_mult',
        'support_level',
        'resistance_level',
        'gross_rr',
        'net_rr',
        'entry_stages',
        'rationale',
        'invalidation_condition',
        'safeguard_suppressed',
        'calculation_version',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'signal_date' => 'date',
            'is_latest' => 'boolean',
            'entry_low' => 'decimal:6',
            'entry_high' => 'decimal:6',
            'confirmation_price' => 'decimal:6',
            'stop_loss' => 'decimal:6',
            'invalidation_price' => 'decimal:6',
            'target_1' => 'decimal:6',
            'target_2' => 'decimal:6',
            'trailing_stop_atr_mult' => 'decimal:2',
            'support_level' => 'decimal:6',
            'resistance_level' => 'decimal:6',
            'gross_rr' => 'decimal:4',
            'net_rr' => 'decimal:4',
            'entry_stages' => 'array',
            'rationale' => 'array',
            'safeguard_suppressed' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
