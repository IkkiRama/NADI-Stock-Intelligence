<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exchange extends Model
{
    protected $fillable = [
        'code',
        'name',
        'mic',
        'country_code',
        'timezone',
        'currency',
        'default_lot_size',
        'sessions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sessions' => 'array',
            'is_active' => 'boolean',
            'default_lot_size' => 'integer',
        ];
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(MarketHoliday::class);
    }

    public function instruments(): HasMany
    {
        return $this->hasMany(Instrument::class);
    }

    public function stockIndices(): HasMany
    {
        return $this->hasMany(StockIndex::class);
    }
}
