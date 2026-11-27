<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentScoreHistory extends Model
{
    protected $table = 'instrument_score_history';

    public $timestamps = false;

    protected $fillable = [
        'instrument_id',
        'score_date',
        'overall_score',
        'components',
        'calculation_version',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'score_date' => 'date',
            'overall_score' => 'decimal:2',
            'components' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
