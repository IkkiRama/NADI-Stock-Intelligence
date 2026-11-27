<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Portfolio extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'reporting_currency',
        'cost_basis_method',
        'is_default',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
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
