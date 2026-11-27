<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndexQuoteLatest extends Model
{
    protected $table = 'index_quotes_latest';

    protected $primaryKey = 'stock_index_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'stock_index_id',
        'trade_date',
        'close',
        'prev_close',
        'change',
        'change_pct',
        'volume',
        'data_source_id',
        'is_demo',
        'refreshed_at',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'close' => 'decimal:6',
            'prev_close' => 'decimal:6',
            'change' => 'decimal:6',
            'change_pct' => 'decimal:8',
            'volume' => 'integer',
            'is_demo' => 'boolean',
            'refreshed_at' => 'datetime',
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
