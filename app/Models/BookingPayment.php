<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingPayment extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'provider_id', 'psychologist_user_id', 'service_id',
        'start_at', 'end_at', 'payment_type', 'total_amount', 'amount_paid', 'balance',
        'method_name', 'wallet_number', 'reference_number', 'receipt_path',
        'status', 'reviewed_by', 'reviewed_at', 'reject_reason',
    ];

    protected $casts = [
        'start_at'     => 'datetime',
        'end_at'       => 'datetime',
        'reviewed_at'  => 'datetime',
        'total_amount' => 'float',
        'amount_paid'  => 'float',
        'balance'      => 'float',
        'approved_at'  => 'datetime',
        'completed_at' => 'datetime',
        'flow_done_at' => 'datetime',
    ];
}