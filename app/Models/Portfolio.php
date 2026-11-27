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

    public const DEFAULT_RISK_REBALANCE_PREFS = [
        'cash_buffer_pct' => 5.0,
        'drift_band_pct' => 2.5,
        'risk_free_rate_pct' => null,
        'exclude_safety_flagged_buys' => true,
    ];

    protected $fillable = [
        'user_id',
        'name',
        'reporting_currency',
        'cost_basis_method',
        'concentration_thresholds',
        'risk_rebalance_prefs',
        'is_default',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'concentration_thresholds' => 'array',
            'risk_rebalance_prefs' => 'array',
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

    /**
     * @return array{cash_buffer_pct: float, drift_band_pct: float, risk_free_rate_pct: float|null, exclude_safety_flagged_buys: bool}
     */
    public function mergedRiskRebalancePrefs(): array
    {
        $prefs = self::DEFAULT_RISK_REBALANCE_PREFS;
        $raw = $this->risk_rebalance_prefs ?? [];

        if (isset($raw['cash_buffer_pct']) && is_numeric($raw['cash_buffer_pct'])) {
            $prefs['cash_buffer_pct'] = (float) $raw['cash_buffer_pct'];
        }
        if (isset($raw['drift_band_pct']) && is_numeric($raw['drift_band_pct'])) {
            $prefs['drift_band_pct'] = (float) $raw['drift_band_pct'];
        }
        if (array_key_exists('risk_free_rate_pct', $raw)) {
            $prefs['risk_free_rate_pct'] = is_numeric($raw['risk_free_rate_pct']) ? (float) $raw['risk_free_rate_pct'] : null;
        }
        if (array_key_exists('exclude_safety_flagged_buys', $raw)) {
            $prefs['exclude_safety_flagged_buys'] = (bool) $raw['exclude_safety_flagged_buys'];
        }

        return $prefs;
    }

    public function rebalanceTargets(): HasMany
    {
        return $this->hasMany(PortfolioRebalanceTarget::class);
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
