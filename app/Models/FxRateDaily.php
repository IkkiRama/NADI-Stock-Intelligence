<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxRateDaily extends Model
{
    protected $table = 'fx_rates_daily';

    protected $fillable = [
        'base_currency',
        'quote_currency',
        'rate_date',
        'rate',
        'data_source_id',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate' => 'decimal:8',
            'is_demo' => 'boolean',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
