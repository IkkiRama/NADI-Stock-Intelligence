<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentPriceDaily extends Model
{
    protected $table = 'instrument_prices_daily';

    protected $fillable = [
        'instrument_id',
        'trade_date',
        'open',
        'high',
        'low',
        'close',
        'adj_close',
        'volume',
        'value_traded',
        'data_source_id',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'open' => 'decimal:6',
            'high' => 'decimal:6',
            'low' => 'decimal:6',
            'close' => 'decimal:6',
            'adj_close' => 'decimal:6',
            'volume' => 'integer',
            'value_traded' => 'decimal:4',
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
