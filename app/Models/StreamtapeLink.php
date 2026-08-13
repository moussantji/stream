<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StreamtapeLink extends Model
{
    protected $fillable = [
        'batch_id',
        'total',
        'subject_id',
        'subject_type',
        'title',
        'season',
        'episode',
        'resolution',
        'language',
        'folder',
        'source_url',
        'subtitle_urls',
        'file_id',
        'streamtape_url',
        'status',
        'error',
        'bytes_loaded',
        'bytes_total',
    ];

    protected function casts(): array
    {
        return [
            'subject_type' => 'integer',
            'season' => 'integer',
            'episode' => 'integer',
            'resolution' => 'integer',
            'total' => 'integer',
            'subtitle_urls' => 'array',
            'bytes_loaded' => 'integer',
            'bytes_total' => 'integer',
            'convert_attempts' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /** True while the file is still being fetched / converted by Streamtape. */
    public function isPending(): bool
    {
        return ! in_array($this->status, ['done', 'failed'], true);
    }
}
