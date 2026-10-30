<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomeContent extends Model
{
    protected $fillable = ['key', 'content', 'updated_by'];
    protected $casts = ['content' => 'array'];
}
