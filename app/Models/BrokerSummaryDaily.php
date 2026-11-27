<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokerSummaryDaily extends Model
{
    protected $table = 'broker_summaries_daily';

    protected $fillable = [
        'instrument_id',
        'broker_id',
        'broker_code',
        'broker_category',
        'trade_date',
        'buy_volume',
        'sell_volume',
        'net_volume',
        'buy_value',
        'sell_value',
        'net_value',
        'avg_buy_price',
        'avg_sell_price',
        'currency',
        'data_source_id',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'trade_date' => 'date',
            'buy_volume' => 'integer',
            'sell_volume' => 'integer',
            'net_volume' => 'integer',
            'buy_value' => 'decimal:4',
            'sell_value' => 'decimal:4',
            'net_value' => 'decimal:4',
            'avg_buy_price' => 'decimal:6',
            'avg_sell_price' => 'decimal:6',
            'is_demo' => 'boolean',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
