<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NewsArticle extends Model
{
    protected $fillable = [
        'data_source_id',
        'external_id',
        'source_name',
        'title',
        'slug',
        'summary',
        'body',
        'url',
        'category',
        'country_code',
        'language',
        'impact_tag',
        'published_at',
        'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function instruments(): BelongsToMany
    {
        return $this->belongsToMany(Instrument::class, 'news_article_instrument');
    }
}
