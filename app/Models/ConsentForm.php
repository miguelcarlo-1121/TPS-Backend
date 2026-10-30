<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsentForm extends Model
{
    protected $fillable = [
        'booking_payment_id', 'user_id', 'intake', 'consent', 'dsm5',
        'signed_name', 'signed_date', 'is_minor', 'guardian_name',
        'guardian_relation', 'submitted_at',
    ];

    protected $casts = [
        'intake'       => 'array',
        'consent'      => 'array',
        'dsm5'         => 'array',
        'is_minor'     => 'boolean',
        'signed_date'  => 'date:Y-m-d',
        'submitted_at' => 'datetime',
    ];
}