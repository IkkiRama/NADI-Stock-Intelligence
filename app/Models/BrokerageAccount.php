<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrokerageAccount extends Model
{
    protected $fillable = [
        'user_id',
        'portfolio_id',
        'broker_id',
        'account_name',
        'account_label',
        'base_currency',
        'cost_basis_method',
        'cash_balance',
        'net_contributions',
        'realized_pnl_avg',
        'realized_pnl_fifo',
        'dividends_received',
        'fees_paid',
        'is_demo',
        'is_active',
        'last_reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'cash_balance' => 'decimal:4',
            'net_contributions' => 'decimal:4',
            'realized_pnl_avg' => 'decimal:4',
            'realized_pnl_fifo' => 'decimal:4',
            'dividends_received' => 'decimal:4',
            'fees_paid' => 'decimal:4',
            'is_demo' => 'boolean',
            'is_active' => 'boolean',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PortfolioTransaction::class);
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(PortfolioHolding::class);
    }

    public function costLots(): HasMany
    {
        return $this->hasMany(PortfolioCostLot::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(PortfolioReconciliation::class)->orderByDesc('id');
    }
}
