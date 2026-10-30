<?php

namespace App\Http\Controllers;

use App\Models\BookingPayment;
use App\Models\ClientProfile;
use App\Models\PaymentMethod;
use App\Models\Provider;
use App\Models\SlotHold;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookingPaymentController extends Controller
{
    private const SERVICE_NAMES = [
        'psychotherapy' => 'Psychotherapy',
        'aba'           => 'ABA Therapy',
        'assessment'    => 'Assessment',
    ];

    // ---------------------------------------------------------------
    // CLIENT
    // ---------------------------------------------------------------

    // GET /api/booking/payment-options?service=psychotherapy
    public function options(Request $request)
    {
        $data = $request->validate(['service' => ['required', 'string', 'max:50']]);

        $settings = DB::table('payment_settings')->first();
        $price    = (float) DB::table('service_prices')->where('service_id', $data['service'])->value('price');

        return response()->json([
            'downpayment_allowed' => !in_array($data['service'], PaymentSettingsController::FULL_ONLY, true),
            'price'               => $price,
            'downpayment_percent' => (int) ($settings->downpayment_percent ?? 50),
            'note'                => $settings->note ?? '',
            'methods'             => PaymentMethod::where('enabled', true)
                ->orderBy('sort')->orderBy('id')->get()
                ->map(fn ($m) => $m->toArrayPublic())->values(),
        ]);
    }

    // POST /api/booking/payments
    public function submit(Request $request)
    {
        
        $data = $request->validate([
            'hold_id'          => ['required', 'integer'],
            'type'             => ['required', 'in:full,down'],
            'method_id'        => ['required', 'integer'],
            'wallet_number'    => ['required', 'regex:/^\d{6,20}$/'],
            'reference_number' => ['required', 'regex:/^[A-Za-z0-9-]{6,60}$/'],
            'receipt'          => ['required', 'string'],
        ], [
            'wallet_number.regex'    => 'Enter a valid account / wallet number (digits only).',
            'reference_number.regex' => 'Reference number must be 6-60 letters or numbers.',
        ]);

        $user = $request->user();

        if (!ClientProfile::where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Please complete your details first.'], 422);
        }

        if (!preg_match('/^data:image\/jpeg;base64,/', $data['receipt'])) {
            return response()->json(['message' => 'Please upload a JPG or PNG screenshot.'], 422);
        }
        $binary = base64_decode(substr($data['receipt'], strpos($data['receipt'], ',') + 1), true);
        if ($binary === false || strlen($binary) > 3 * 1024 * 1024 || @getimagesizefromstring($binary) === false) {
            return response()->json(['message' => 'That receipt image could not be used. Try a smaller one.'], 422);
        }

        return DB::transaction(function () use ($data, $user, $binary) {
            $hold = SlotHold::where('id', $data['hold_id'])
                ->where('user_id', $user->id)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$hold) {
                return response()->json([
                    'message' => 'Your held time has expired. Please pick a date and time again.',
                ], 409);
            }

            $price = (float) DB::table('service_prices')->where('service_id', $hold->service_id)->value('price');
            if ($price <= 0) {
                return response()->json(['message' => 'The price for this service is not set yet.'], 422);
            }

            $percent  = (int) (DB::table('payment_settings')->value('downpayment_percent') ?: 50);
            $fullOnly = in_array($hold->service_id, PaymentSettingsController::FULL_ONLY, true);
            if ($data['type'] === 'down' && ($fullOnly || $percent >= 100)) {
                return response()->json(['message' => 'Downpayment is not available for this service. Please pay in full.'], 422);
            }

            $method = PaymentMethod::where('enabled', true)->find($data['method_id']);
            if (!$method) {
                return response()->json(['message' => 'That payment method is not available.'], 422);
            }

            $paid    = $data['type'] === 'full' ? $price : round($price * $percent / 100, 2);
            $balance = round($price - $paid, 2);

            $path = 'receipts/' . Str::random(40) . '.jpg';
            Storage::disk('public')->put($path, $binary);

            $psychUserId = $hold->service_id === 'psychotherapy'
                ? Provider::where('id', $hold->provider_id)->value('user_id')
                : null;

            $payment = BookingPayment::create([
                'reference'            => 'TF-' . strtoupper(Str::random(8)),
                'user_id'              => $user->id,
                'provider_id'          => $hold->provider_id,
                'psychologist_user_id' => $psychUserId,
                'service_id'           => $hold->service_id,
                'start_at'             => $hold->start_at,
                'end_at'               => $hold->end_at,
                'payment_type'         => $data['type'],
                'total_amount'         => $price,
                'amount_paid'          => $paid,
                'balance'              => $balance,
                'method_name'          => $method->name,
                'wallet_number'        => $data['wallet_number'],
                'reference_number'     => $data['reference_number'],
                'receipt_path'         => $path,
                'status'               => 'to_verify',
            ]);

            $hold->delete(); // ang booking_payments na ang naka-block sa slot

            return response()->json(['payment' => [
                'id'         => $payment->id,
                'reference'  => $payment->reference,
                'status'     => $payment->status,
                'amount_paid' => $payment->amount_paid,
                'balance'    => $payment->balance,
            ]], 201);
        });
        
    }
    // GET /api/booking/payments/latest
public function latest(Request $request)
{
    $p = BookingPayment::where('user_id', $request->user()->id)->latest('id')->first();

    if (!$p) {
        return response()->json(['payment' => null]);
    }

    if ($p->status === 'rejected' || $p->approval_status === 'declined') {
        $stage = 'rejected';
    } elseif ($p->status === 'to_verify') {
        $stage = 'payment_pending';
    } elseif ($p->approval_status === 'approved') {
        $stage = 'approved';
    } else {
        $stage = 'psychologist_pending';
    }

    $psych = $p->service_id === 'psychotherapy' && $p->psychologist_user_id
        ? User::find($p->psychologist_user_id)
        : null;

    return response()->json(['payment' => [
        'id'              => $p->id,
        'reference'       => $p->reference,
        'stage'           => $stage,
        'reason'          => $p->status === 'rejected' ? $p->reject_reason : $p->decline_reason,
        'rejected_by'     => $p->status === 'rejected' ? 'payment' : ($p->approval_status === 'declined' ? 'psychologist' : null),
        'service_id'      => $p->service_id,
        'service_name'    => self::SERVICE_NAMES[$p->service_id] ?? $p->service_id,
        'psychologist'    => $psych ? trim($psych->first_name . ' ' . $psych->last_name) : null,
        'date'            => $p->start_at->format('Y-m-d'),
        'start_time'      => $p->start_at->format('H:i'),
        'end_time'        => $p->end_at->format('H:i'),
        'total_amount'    => $p->total_amount,
        'amount_paid'     => $p->amount_paid,
        'balance'         => $p->balance,
    ]]);
}

    // ---------------------------------------------------------------
    // ADMIN / AFFILIATE
    // ---------------------------------------------------------------

    /**
     * Psychotherapy: makikita lang ng psychologist na pinili.
     * ABA / Assessment: makikita ng lahat ng ADMIN.
     */
    private function scoped(User $user)
    {
        $q = BookingPayment::query();

        if ($user->role === 'AFFILIATE') {
            return $q->where('service_id', 'psychotherapy')
                ->where('psychologist_user_id', $user->id);
        }

        return $q->where(function ($w) use ($user) {
            $w->where('service_id', '!=', 'psychotherapy')
              ->orWhere('psychologist_user_id', $user->id);
        });
    }

    private function format(BookingPayment $p, $profiles, $users): array
    {
        $profile = $profiles[$p->user_id] ?? null;
        $u       = $users[$p->user_id] ?? null;

        return [
            'id'               => $p->id,
            'reference'        => $p->reference,
            'client_name'      => $profile->full_name ?? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
            'client_email'     => $profile->email ?? ($u->email ?? ''),
            'client_mobile'    => $profile->mobile ?? ($u->phone ?? ''),
            'service_id'       => $p->service_id,
            'service_name'     => self::SERVICE_NAMES[$p->service_id] ?? $p->service_id,
            'date'             => $p->start_at->format('Y-m-d'),
            'start_time'       => $p->start_at->format('H:i'),
            'end_time'         => $p->end_at->format('H:i'),
            'payment_type'     => $p->payment_type,
            'method'           => $p->method_name,
            'wallet_number'    => $p->wallet_number,
            'reference_number' => $p->reference_number,
            'total_amount'     => $p->total_amount,
            'amount_paid'      => $p->amount_paid,
            'balance'          => $p->balance,
            'status'           => $p->status,
            'reject_reason'    => $p->reject_reason,
            'receipt_url'      => Storage::disk('public')->url($p->receipt_path),
            'created_at'       => $p->created_at->toIso8601String(),
        ];
    }

    // GET /api/admin/payments  |  GET /api/affiliate/payments
    public function index(Request $request)
    {
        $rows = $this->scoped($request->user())->orderByDesc('created_at')->limit(300)->get();

        $ids      = $rows->pluck('user_id')->unique();
        $profiles = ClientProfile::whereIn('user_id', $ids)->get()->keyBy('user_id');
        $users    = User::whereIn('id', $ids)->get()->keyBy('id');

        $active = $rows->whereIn('status', ['to_verify', 'verified']);

        return response()->json([
            'payments' => $rows->map(fn ($p) => $this->format($p, $profiles, $users))->values(),
            'stats'    => [
                'verified_total' => (float) $rows->where('status', 'verified')->sum('amount_paid'),
                'to_verify'      => $rows->where('status', 'to_verify')->count(),
                'balance_due'    => (float) $active->where('payment_type', 'down')->sum('balance'),
                'full_count'     => $active->where('payment_type', 'full')->count(),
                'down_count'     => $active->where('payment_type', 'down')->count(),
            ],
        ]);
    }

    private function review(Request $request, $id, string $status)
    {
        $user = $request->user();
        $p    = $this->scoped($user)->findOrFail($id);

        if ($p->status !== 'to_verify') {
            return response()->json(['message' => 'This payment was already reviewed.'], 422);
        }

        $reason = null;
        if ($status === 'rejected') {
            $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;
        }

        $p->forceFill([
            'status'        => $status,
            'reviewed_by'   => $user->id,
            'reviewed_at'   => now(),
            'reject_reason' => $reason,
        ])->save();

        $profiles = ClientProfile::where('user_id', $p->user_id)->get()->keyBy('user_id');
        $users    = User::where('id', $p->user_id)->get()->keyBy('id');

        return response()->json(['payment' => $this->format($p, $profiles, $users)]);
    }

    // POST /api/{admin|affiliate}/payments/{id}/verify
    public function verify(Request $request, $id)
    {
        return $this->review($request, $id, 'verified');
    }

    // POST /api/{admin|affiliate}/payments/{id}/reject
    public function reject(Request $request, $id)
    {
        return $this->review($request, $id, 'rejected');
    }
 
public function active(Request $request)
{
    $p = BookingPayment::where('user_id', $request->user()->id)
        ->where('status', '!=', 'rejected')
        ->where('approval_status', '!=', 'declined')
        ->whereNull('cancelled_at')
        ->whereNull('completed_at')
        ->whereNull('flow_done_at')
        ->where('end_at', '>', now())
        ->latest('id')
        ->first();

    return response()->json([
        'active' => (bool) $p,
        'id'     => $p?->id,
    ]);
}


public function finish(Request $request, $id)
{
    $p = BookingPayment::where('user_id', $request->user()->id)->findOrFail($id);

    if ($p->approval_status !== 'approved') {
        return response()->json(['message' => 'This booking is not approved yet.'], 422);
    }

    if (!$p->flow_done_at) {
        $p->forceFill(['flow_done_at' => now()])->save();
    }

    return response()->json(['message' => 'Done']);
}
}