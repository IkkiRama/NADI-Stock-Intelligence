<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketHoliday extends Model
{
    protected $fillable = [
        'exchange_id',
        'date',
        'name',
        'is_half_day',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_half_day' => 'boolean',
        ];
    }

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }
}
