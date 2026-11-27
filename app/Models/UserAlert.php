<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserAlert extends Model
{
    protected $fillable = [
        'user_id',
        'instrument_id',
        'alert_type',
        'threshold_value',
        'horizon',
        'notes',
        'is_active',
        'last_triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'threshold_value' => 'decimal:6',
            'is_active' => 'boolean',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }
}
