<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentScore extends Model
{
    protected $primaryKey = 'instrument_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'instrument_id',
        'as_of_date',
        'overall_score',
        'fundamentals_score',
        'valuation_score',
        'technical_score',
        'risk_score',
        'catalysts_score',
        'sector_percentile',
        'data_coverage',
        'reasons',
        'missing_indicators',
        'weights_used',
        'calculation_version',
        'is_demo',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'overall_score' => 'decimal:2',
            'fundamentals_score' => 'decimal:2',
            'valuation_score' => 'decimal:2',
            'technical_score' => 'decimal:2',
            'risk_score' => 'decimal:2',
            'catalysts_score' => 'decimal:2',
            'sector_percentile' => 'decimal:2',
            'data_coverage' => 'decimal:2',
            'reasons' => 'array',
            'missing_indicators' => 'array',
            'weights_used' => 'array',
            'is_demo' => 'boolean',
            'calculated_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
