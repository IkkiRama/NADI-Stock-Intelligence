<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SafetyConfig extends Model
{
    public const DEFAULT_THRESHOLDS = [
        'safeguard_score' => 65.0,
        'surge_5d_pct' => 0.25,
        'surge_20d_pct' => 0.50,
        'volume_spike_mult' => 3.5,
        'volatility_high' => 0.50,
        'min_avg_value_idr' => 2000000000.0,
        'gap_pct' => 0.06,
        'per_divergence_min' => 45.0,
        'min_free_float_pct' => 10.0,
        'max_top_holder_pct' => 80.0,
    ];

    protected $fillable = [
        'code',
        'name',
        'thresholds',
        'version',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'thresholds' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return array<string, float>
     */
    public function mergedThresholds(): array
    {
        $merged = self::DEFAULT_THRESHOLDS;
        foreach (($this->thresholds ?? []) as $k => $v) {
            if (array_key_exists($k, $merged) && is_numeric($v)) {
                $merged[$k] = (float) $v;
            }
        }

        return $merged;
    }
}
