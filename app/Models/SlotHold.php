<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlotHold extends Model
{
    protected $fillable = [
        'provider_id', 'user_id', 'service_id',
        'assessment_type_id', 'assessment_type_name',
        'start_at', 'end_at', 'expires_at',
    ];

    protected $casts = [
        'start_at'   => 'datetime',
        'end_at'     => 'datetime',
        'expires_at' => 'datetime',
    ];

    
    public function scopeActive($q)
    {
        return $q->where('expires_at', '>', now());
    }

   
    public function scopeOverlapping($q, $start, $end)
    {
        return $q->where('start_at', '<', $end)->where('end_at', '>', $start);
    }
}