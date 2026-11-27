<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Portfolio extends Model
{
    public const DEFAULT_CONCENTRATION_THRESHOLDS = [
        'max_single_stock_pct' => 25.0,
        'max_top3_pct' => 60.0,
        'max_sector_pct' => 40.0,
        'max_country_pct' => 80.0,
        'max_broker_pct' => 75.0,
        'max_currency_pct' => 85.0,
        'hhi_elevated_threshold' => 2500.0,
    ];

    protected $fillable = [
        'user_id',
        'name',
        'reporting_currency',
        'cost_basis_method',
        'concentration_thresholds',
        'is_default',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'concentration_thresholds' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return array<string, float>
     */
    public function mergedConcentrationThresholds(): array
    {
        $merged = self::DEFAULT_CONCENTRATION_THRESHOLDS;
        foreach (($this->concentration_thresholds ?? []) as $k => $v) {
            if (array_key_exists($k, $merged) && is_numeric($v)) {
                $merged[$k] = (float) $v;
            }
        }

        return $merged;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(BrokerageAccount::class);
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(PortfolioHolding::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PortfolioTransaction::class);
    }
}
