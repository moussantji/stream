<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StreamtapeFolder extends Model
{
    protected $fillable = [
        'name',
        'parent',
        'folder_id',
    ];
}
