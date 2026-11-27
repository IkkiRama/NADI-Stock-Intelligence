<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioHolding extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'portfolio_id',
        'brokerage_account_id',
        'instrument_id',
        'quantity',
        'currency',
        'avg_cost_per_share',
        'total_cost_avg',
        'fifo_cost_per_share',
        'total_cost_fifo',
        'realized_pnl_avg',
        'realized_pnl_fifo',
        'dividends_received',
        'is_demo',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'avg_cost_per_share' => 'decimal:6',
            'total_cost_avg' => 'decimal:4',
            'fifo_cost_per_share' => 'decimal:6',
            'total_cost_fifo' => 'decimal:4',
            'realized_pnl_avg' => 'decimal:4',
            'realized_pnl_fifo' => 'decimal:4',
            'dividends_received' => 'decimal:4',
            'is_demo' => 'boolean',
            'updated_at' => 'datetime',
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(BrokerageAccount::class, 'brokerage_account_id');
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
