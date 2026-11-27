<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataImportRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'data_source_id',
        'user_id',
        'dataset',
        'mode',
        'status',
        'rows_processed',
        'rows_inserted',
        'rows_skipped',
        'error_summary',
        'metadata',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'rows_processed' => 'integer',
            'rows_inserted' => 'integer',
            'rows_skipped' => 'integer',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
