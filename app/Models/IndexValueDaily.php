<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndexValueDaily extends Model
{
    protected $table = 'index_values_daily';

    protected $fillable = [
        'stock_index_id',
        'trade_date',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'data_source_id',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'open' => 'decimal:6',
            'high' => 'decimal:6',
            'low' => 'decimal:6',
            'close' => 'decimal:6',
            'volume' => 'integer',
            'is_demo' => 'boolean',
        ];
    }

    public function stockIndex(): BelongsTo
    {
        return $this->belongsTo(StockIndex::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
