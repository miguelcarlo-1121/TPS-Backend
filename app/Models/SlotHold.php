<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlotHold extends Model
{
    protected $fillable = [
        'provider_id', 'user_id', 'service_id',
        'start_at', 'end_at', 'expires_at',
    ];

    protected $casts = [
        'start_at'   => 'datetime',
        'end_at'     => 'datetime',
        'expires_at' => 'datetime',
    ];
}