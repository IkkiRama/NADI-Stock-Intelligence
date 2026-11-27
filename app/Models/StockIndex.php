<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockIndex extends Model
{
    protected $fillable = [
        'code',
        'name',
        'country_code',
        'exchange_id',
        'currency',
        'index_type',
        'is_benchmark',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_benchmark' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    public function constituents(): HasMany
    {
        return $this->hasMany(IndexConstituent::class);
    }

    public function dailyValues(): HasMany
    {
        return $this->hasMany(IndexValueDaily::class);
    }

    public function latestQuote(): HasOne
    {
        return $this->hasOne(IndexQuoteLatest::class);
    }
}
