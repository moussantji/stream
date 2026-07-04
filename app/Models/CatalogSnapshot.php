<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogSnapshot extends Model
{
    protected $fillable = ['cache_key', 'payload'];
}
