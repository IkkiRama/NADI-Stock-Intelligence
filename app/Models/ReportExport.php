<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    protected $fillable = [
        'user_id',
        'portfolio_id',
        'report_type',
        'format',
        'reporting_currency',
        'cost_basis_method',
        'range_code',
        'parameters',
        'row_count',
        'is_demo',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'row_count' => 'integer',
            'is_demo' => 'boolean',
            'generated_at' => 'datetime',
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
}
