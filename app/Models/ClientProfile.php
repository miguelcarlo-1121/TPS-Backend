<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientProfile extends Model
{
    protected $fillable = [
        'user_id', 'full_name', 'birthdate', 'email', 'mobile',
        'emergency_name', 'emergency_relation', 'emergency_phone',
    ];

    protected $casts = [
        'birthdate' => 'date:Y-m-d',
    ];
}