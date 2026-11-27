<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentQuoteLatest extends Model
{
    protected $table = 'instrument_quotes_latest';

    protected $primaryKey = 'instrument_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'instrument_id',
        'trade_date',
        'close',
        'prev_close',
        'change',
        'change_pct',
        'volume',
        'value_traded',
        'data_source_id',
        'is_demo',
        'refreshed_at',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'close' => 'decimal:6',
            'prev_close' => 'decimal:6',
            'change' => 'decimal:6',
            'change_pct' => 'decimal:8',
            'volume' => 'integer',
            'value_traded' => 'decimal:4',
            'is_demo' => 'boolean',
            'refreshed_at' => 'datetime',
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
