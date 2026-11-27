<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_alert_id',
        'user_id',
        'instrument_id',
        'triggered_date',
        'message',
        'context',
        'read_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'triggered_date' => 'date',
            'context' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function userAlert(): BelongsTo
    {
        return $this->belongsTo(UserAlert::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
