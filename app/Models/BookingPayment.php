<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BookingPayment extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'provider_id', 'psychologist_user_id', 'service_id',
        'assessment_type_id', 'assessment_type_name',
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

    /**
     * I-mark na completed (completed_at = end_at) ang lahat ng APPROVED na appointment
     * na lumipas na ang oras sa Asia/Manila. Idempotent: ligtas tawagin kahit ilang beses.
     * Returns: bilang ng na-update.
     */
    public static function completeEnded(): int
    {
        // Booking times are stored as Asia/Manila wall clock; compare against Manila "now"
        // even if APP_TIMEZONE was left at UTC.
        $nowManila = now('Asia/Manila')->format('Y-m-d H:i:s');

        return static::query()
            ->where('approval_status', 'approved')
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->where('end_at', '<=', $nowManila)
            ->update(['completed_at' => DB::raw('end_at')]);
    }
}