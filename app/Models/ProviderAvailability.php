<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderAvailability extends Model
{
    protected $table = 'provider_availability';

    protected $fillable = ['provider_id', 'start_at', 'end_at', 'status', 'created_by_role'];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at'   => 'datetime',
    ];
}