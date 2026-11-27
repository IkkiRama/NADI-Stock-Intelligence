<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockSafetyHistory extends Model
{
    public $timestamps = false;

    protected $table = 'stock_safety_history';

    protected $fillable = [
        'instrument_id',
        'assessment_date',
        'risk_score',
        'risk_category',
        'safeguard_triggered',
        'triggered_count',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'assessment_date' => 'date',
            'risk_score' => 'decimal:2',
            'safeguard_triggered' => 'boolean',
            'triggered_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
