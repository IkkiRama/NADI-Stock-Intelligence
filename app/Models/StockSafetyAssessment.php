<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockSafetyAssessment extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'instrument_id';

    public $incrementing = false;

    protected $fillable = [
        'instrument_id',
        'as_of_date',
        'risk_score',
        'risk_category',
        'safeguard_triggered',
        'triggered_rules',
        'data_confidence',
        'missing_factors',
        'calculation_version',
        'is_demo',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'risk_score' => 'decimal:2',
            'safeguard_triggered' => 'boolean',
            'triggered_rules' => 'array',
            'data_confidence' => 'decimal:2',
            'missing_factors' => 'array',
            'is_demo' => 'boolean',
            'calculated_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
