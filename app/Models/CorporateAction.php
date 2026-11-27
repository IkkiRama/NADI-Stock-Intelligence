<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporateAction extends Model
{
    protected $fillable = [
        'instrument_id',
        'action_type',
        'ex_date',
        'record_date',
        'payment_date',
        'declared_currency',
        'cash_amount',
        'ratio_numerator',
        'ratio_denominator',
        'notes',
        'data_source_id',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'ex_date' => 'date',
            'record_date' => 'date',
            'payment_date' => 'date',
            'cash_amount' => 'decimal:6',
            'ratio_numerator' => 'decimal:6',
            'ratio_denominator' => 'decimal:6',
            'is_demo' => 'boolean',
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
