<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'locale',
        'appearance',
        'density',
        'analysis_mode',
        'default_currency',
        'default_market',
        'default_timeframe',
        'timezone',
        'dashboard_layout',
        'notification_prefs',
        'chart_presets',
    ];

    protected function casts(): array
    {
        return [
            'dashboard_layout' => 'array',
            'notification_prefs' => 'array',
            'chart_presets' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
