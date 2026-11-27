<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioRebalanceTarget extends Model
{
    protected $fillable = [
        'user_id',
        'portfolio_id',
        'instrument_id',
        'target_weight_pct',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'target_weight_pct' => 'decimal:2',
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

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
