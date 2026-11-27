<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentFundamentalLatest extends Model
{
    protected $table = 'instrument_fundamentals_latest';

    protected $primaryKey = 'instrument_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'instrument_id',
        'as_of_date',
        'period_label',
        'statement_currency',
        'market_cap',
        'market_cap_idr',
        'eps',
        'per',
        'pbv',
        'roe',
        'roa',
        'der',
        'current_ratio',
        'net_margin',
        'revenue_growth_yoy',
        'earnings_growth_yoy',
        'ocf',
        'fcf',
        'dividend_yield',
        'dividend_payout_ratio',
        'is_financial_sector',
        'missing_fields',
        'data_source_id',
        'is_demo',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'market_cap' => 'decimal:4',
            'market_cap_idr' => 'decimal:4',
            'eps' => 'decimal:6',
            'per' => 'decimal:6',
            'pbv' => 'decimal:6',
            'roe' => 'decimal:8',
            'roa' => 'decimal:8',
            'der' => 'decimal:6',
            'current_ratio' => 'decimal:6',
            'net_margin' => 'decimal:8',
            'revenue_growth_yoy' => 'decimal:8',
            'earnings_growth_yoy' => 'decimal:8',
            'ocf' => 'decimal:4',
            'fcf' => 'decimal:4',
            'dividend_yield' => 'decimal:8',
            'dividend_payout_ratio' => 'decimal:8',
            'is_financial_sector' => 'boolean',
            'missing_fields' => 'array',
            'is_demo' => 'boolean',
            'calculated_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
