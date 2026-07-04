<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    protected $fillable = [
        'subject_id',
        'subject_type',
        'title',
        'cover',
        'description',
        'year',
        'imdb_rating',
        'country',
        'duration_seconds',
        'genres',
        'detail_path',
        'payload',
        'seen_count',
    ];

    protected function casts(): array
    {
        return [
            'subject_type' => 'integer',
            'year' => 'integer',
            'imdb_rating' => 'float',
            'duration_seconds' => 'integer',
            'genres' => 'array',
            'payload' => 'array',
            'seen_count' => 'integer',
        ];
    }
}
