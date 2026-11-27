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
        'supports_live_sync',
        'website_url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'supports_live_sync' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function brokerageAccounts(): HasMany
    {
        return $this->hasMany(BrokerageAccount::class);
    }
}
