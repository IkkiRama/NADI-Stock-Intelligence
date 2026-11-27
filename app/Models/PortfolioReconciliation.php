<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioReconciliation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'brokerage_account_id',
        'statement_date',
        'statement_cash_balance',
        'computed_cash_balance',
        'cash_difference',
        'statement_positions',
        'status',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'statement_cash_balance' => 'decimal:4',
            'computed_cash_balance' => 'decimal:4',
            'cash_difference' => 'decimal:4',
            'statement_positions' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BrokerageAccount::class, 'brokerage_account_id');
    }
}
