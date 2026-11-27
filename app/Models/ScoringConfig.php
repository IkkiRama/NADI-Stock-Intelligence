<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScoringConfig extends Model
{
    protected $fillable = [
        'code',
        'name',
        'weights',
        'version',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weights' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
