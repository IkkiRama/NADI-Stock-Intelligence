<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Broker extends Model
{
    protected $fillable = [
        'code',
        'name',
        'exchange_member_code',
        'country_code',
        'default_currency',
        'csv_adapter',
        'broker_category',
        'typical_buy_fee_pct',
        'typical_sell_fee_pct',
        'supports_live_sync',
        'website_url',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'typical_buy_fee_pct' => 'decimal:6',
            'typical_sell_fee_pct' => 'decimal:6',
            'supports_live_sync' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function brokerageAccounts(): HasMany
    {
        return $this->hasMany(BrokerageAccount::class);
    }

    public function brokerSummaries(): HasMany
    {
        return $this->hasMany(BrokerSummaryDaily::class);
    }
}
