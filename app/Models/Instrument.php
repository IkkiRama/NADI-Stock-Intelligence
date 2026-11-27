<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Instrument extends Model
{
    protected $fillable = [
        'exchange_id',
        'symbol',
        'name',
        'type',
        'currency',
        'sector',
        'industry',
        'country_code',
        'isin',
        'lot_size',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'lot_size' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    public function dailyPrices(): HasMany
    {
        return $this->hasMany(InstrumentPriceDaily::class);
    }

    public function latestQuote(): HasOne
    {
        return $this->hasOne(InstrumentQuoteLatest::class);
    }

    public function corporateActions(): HasMany
    {
        return $this->hasMany(CorporateAction::class)->orderByDesc('ex_date');
    }

    public function financialStatements(): HasMany
    {
        return $this->hasMany(FinancialStatement::class)->orderByDesc('period_end_date');
    }

    public function latestFundamentals(): HasOne
    {
        return $this->hasOne(InstrumentFundamentalLatest::class);
    }

    public function latestTechnicals(): HasOne
    {
        return $this->hasOne(InstrumentTechnicalLatest::class);
    }

    public function latestScore(): HasOne
    {
        return $this->hasOne(InstrumentScore::class);
    }

    public function scoreHistory(): HasMany
    {
        return $this->hasMany(InstrumentScoreHistory::class)->orderBy('score_date');
    }
}
