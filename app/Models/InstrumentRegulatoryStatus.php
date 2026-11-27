<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentRegulatoryStatus extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'instrument_id';

    public $incrementing = false;

    protected $fillable = [
        'instrument_id',
        'is_suspended',
        'has_exchange_warning',
        'has_special_monitoring',
        'free_float_pct',
        'top_holder_pct',
        'status_notes',
        'source_note',
        'is_demo',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_suspended' => 'boolean',
            'has_exchange_warning' => 'boolean',
            'has_special_monitoring' => 'boolean',
            'free_float_pct' => 'decimal:2',
            'top_holder_pct' => 'decimal:2',
            'is_demo' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
