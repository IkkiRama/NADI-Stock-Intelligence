<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'portfolio_id',
        'brokerage_account_id',
        'instrument_id',
        'counterparty_account_id',
        'reversal_of_id',
        'reversed_at',
        'transaction_type',
        'trade_date',
        'settlement_date',
        'quantity',
        'price',
        'gross_amount',
        'broker_fee',
        'exchange_fee',
        'tax_amount',
        'net_cash_amount',
        'transaction_currency',
        'fx_rate_to_account',
        'ratio_numerator',
        'ratio_denominator',
        'import_source',
        'external_ref',
        'dedupe_hash',
        'reversal_reason',
        'notes',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'settlement_date' => 'date',
            'reversed_at' => 'datetime',
            'quantity' => 'decimal:6',
            'price' => 'decimal:6',
            'gross_amount' => 'decimal:4',
            'broker_fee' => 'decimal:4',
            'exchange_fee' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'net_cash_amount' => 'decimal:4',
            'fx_rate_to_account' => 'decimal:8',
            'ratio_numerator' => 'decimal:6',
            'ratio_denominator' => 'decimal:6',
            'is_demo' => 'boolean',
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

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
