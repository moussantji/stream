<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchHistory extends Model
{
    /** @use HasFactory<\Database\Factories\WatchHistoryFactory> */
    use HasFactory;

    protected $table = 'watch_histories';

    protected $fillable = [
        'user_id',
        'subject_id',
        'subject_type',
        'title',
        'cover',
        'detail_path',
        'season',
        'episode',
        'position_seconds',
        'duration_seconds',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'subject_type' => 'integer',
            'season' => 'integer',
            'episode' => 'integer',
            'position_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
