<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioCostLot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'brokerage_account_id',
        'instrument_id',
        'source_transaction_id',
        'acquired_date',
        'original_quantity',
        'remaining_quantity',
        'unit_cost',
        'total_cost',
        'currency',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'acquired_date' => 'date',
            'original_quantity' => 'decimal:6',
            'remaining_quantity' => 'decimal:6',
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:4',
            'created_at' => 'datetime',
        ];
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
