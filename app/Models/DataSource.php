<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataSource extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'datasets',
        'is_enabled',
        'is_demo',
        'attribution',
        'license_notes',
        'config',
        'last_success_at',
        'last_failure_at',
    ];

    protected function casts(): array
    {
        return [
            'datasets' => 'array',
            'is_enabled' => 'boolean',
            'is_demo' => 'boolean',
            'config' => 'array',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }
}
