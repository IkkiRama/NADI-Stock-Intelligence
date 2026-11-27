<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResearchNote extends Model
{
    protected $fillable = [
        'user_id',
        'instrument_id',
        'title',
        'template_type',
        'conviction',
        'stance',
        'target_price',
        'target_currency',
        'horizon',
        'summary',
        'thesis_body',
        'catalysts',
        'risks',
        'invalidation_criteria',
        'tags',
        'is_pinned',
        'reviewed_at',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'target_price' => 'decimal:6',
            'tags' => 'array',
            'is_pinned' => 'boolean',
            'reviewed_at' => 'date',
            'is_demo' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }
}
