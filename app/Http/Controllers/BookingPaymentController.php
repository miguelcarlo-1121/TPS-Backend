<?php

namespace App\Http\Controllers;

use App\Models\BookingPayment;
use App\Models\ClientProfile;
use App\Models\PaymentMethod;
use App\Models\Provider;
use App\Models\SlotHold;
use App\Models\User;
use App\Models\ProviderAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $data = $request->validate([
            'service'            => ['required', 'string', 'max:50'],
            'assessment_type_id' => ['nullable', 'integer'],
        ]);

        $settings = DB::table('payment_settings')->first();
        if ($data['service'] === 'assessment' && !empty($data['assessment_type_id'])) {
            $typePrice = (float) DB::table('assessment_types')->where('id', $data['assessment_type_id'])->value('price');
            $price = $typePrice > 0 ? $typePrice : (float) DB::table('service_prices')->where('service_id', 'assessment')->value('price');
        } else {
            $price = (float) DB::table('service_prices')->where('service_id', $data['service'])->value('price');
        }
        $fixedDownpayment = $data['service'] === 'psychotherapy'
            ? (float) ($settings->psychotherapy_downpayment_amount ?? 750)
            : ($data['service'] === 'assessment'
                ? (float) ($settings->assessment_downpayment_amount ?? 750)
                : null);

        return response()->json([
            'downpayment_allowed' => !in_array($data['service'], PaymentSettingsController::FULL_ONLY, true)
                && ($fixedDownpayment === null || ($fixedDownpayment > 0 && $fixedDownpayment < $price)),
            'price'               => $price,
            'downpayment_percent' => (int) ($settings->downpayment_percent ?? 50),
            'downpayment_amount'  => $fixedDownpayment,
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

            if ($hold->service_id === 'assessment' && $hold->assessment_type_id) {
                $typePrice = (float) DB::table('assessment_types')->where('id', $hold->assessment_type_id)->value('price');
                $price = $typePrice > 0 ? $typePrice : (float) DB::table('service_prices')->where('service_id', $hold->service_id)->value('price');
            } else {
                $price = (float) DB::table('service_prices')->where('service_id', $hold->service_id)->value('price');
            }

            if ($price <= 0) {
                return response()->json(['message' => 'The price for this service is not set yet.'], 422);
            }

            $percent  = (int) (DB::table('payment_settings')->value('downpayment_percent') ?: 50);
            $psychotherapyDownpayment = (float) (DB::table('payment_settings')->value('psychotherapy_downpayment_amount') ?: 750);
            $assessmentDownpayment = (float) (DB::table('payment_settings')->value('assessment_downpayment_amount') ?: 750);
            $fullOnly = in_array($hold->service_id, PaymentSettingsController::FULL_ONLY, true);
            $fixedDownpaymentService = in_array($hold->service_id, ['psychotherapy', 'assessment'], true);
            $downAmount = $hold->service_id === 'psychotherapy'
                ? $psychotherapyDownpayment
                : ($hold->service_id === 'assessment'
                    ? $assessmentDownpayment
                    : round($price * $percent / 100, 2));
            if ($data['type'] === 'down' && (
                $fullOnly
                || ($fixedDownpaymentService
                    ? $downAmount <= 0 || $downAmount >= $price
                    : $percent >= 100)
            )) {
                return response()->json(['message' => 'Downpayment is not available for this service. Please pay in full.'], 422);
            }

            $method = PaymentMethod::where('enabled', true)->find($data['method_id']);
            if (!$method) {
                return response()->json(['message' => 'That payment method is not available.'], 422);
            }

            $paid    = $data['type'] === 'full' ? $price : $downAmount;
            $balance = round($price - $paid, 2);

            $path = 'receipts/' . Str::random(40) . '.jpg';
            Storage::disk('public')->put($path, $binary);

            $psychUserId = $hold->service_id === 'psychotherapy'
                ? Provider::where('id', $hold->provider_id)->value('user_id')
                : null;

            $attrs = [
                'user_id'              => $user->id,
                'provider_id'          => $hold->provider_id,
                'psychologist_user_id' => $psychUserId,
                'service_id'           => $hold->service_id,
                'assessment_type_id'   => $hold->assessment_type_id,
                'assessment_type_name' => $hold->assessment_type_name,
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
            ];

            // Nag-bayad ulit ang parehong client para sa parehong oras? I-update ang na-reject na row.
            $existing = BookingPayment::where('user_id', $user->id)
                ->where('status', 'rejected')
                ->where('provider_id', $hold->provider_id)
                ->where('service_id', $hold->service_id)
                ->where('start_at', $hold->start_at)
                ->where('end_at', $hold->end_at)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->receipt_path) {
                    Storage::disk('public')->delete($existing->receipt_path);
                }

                $existing->forceFill($attrs + [
                    'status'          => 'to_verify',
                    'approval_status' => 'pending',
                    'reviewed_by'     => null,
                    'reviewed_at'     => null,
                    'reject_reason'   => null,
                    'created_at'      => now(), // lalabas ulit sa itaas bilang bago
                ])->save();

                $payment = $existing;
            } else {
                $payment = BookingPayment::create($attrs + [
                    'reference' => 'TF-' . strtoupper(Str::random(8)),
                    'status'    => 'to_verify',
                ]);
            }

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

        $reviewerId = $p->status === 'rejected'
            ? $p->reviewed_by
            : ($p->approval_status === 'declined' ? $p->approved_by : null);
        $reviewer = $reviewerId ? User::find($reviewerId) : null;
        return response()->json(['payment' => [
            'id'              => $p->id,
            'reference'       => $p->reference,
            'stage'           => $stage,
            'reason'          => $p->status === 'rejected' ? $p->reject_reason : $p->decline_reason,
            'rejected_by'     => $p->status === 'rejected'
                ? 'payment'
                : ($p->approval_status === 'declined'
                    ? ($p->service_id === 'psychotherapy' ? 'psychologist' : 'clinic')
                    : null),
            'reviewer'        => $reviewer ? trim($reviewer->first_name . ' ' . $reviewer->last_name) : null,
            'reviewer_role'   => $reviewer?->role,
            'service_id'      => $p->service_id,
            'service_name'    => $p->assessment_type_name ? ((self::SERVICE_NAMES[$p->service_id] ?? $p->service_id) . ' (' . $p->assessment_type_name . ')') : (self::SERVICE_NAMES[$p->service_id] ?? $p->service_id),
            'assessment_type' => $p->assessment_type_name,
            'psychologist'    => $psych ? trim($psych->first_name . ' ' . $psych->last_name) : null,
            'date'            => $p->start_at->format('Y-m-d'),
            'start_time'      => $p->start_at->format('H:i'),
            'end_time'        => $p->end_at->format('H:i'),
            'total_amount'    => $p->total_amount,
            'amount_paid'     => $p->amount_paid,
            'balance'         => $p->balance,
        ]]);
    }

    public function retry(Request $request, $id)
    {
        $user = $request->user();
        $p    = BookingPayment::where('user_id', $user->id)->findOrFail($id);

        if ($p->status !== 'rejected') {
            return response()->json(['message' => 'Only a rejected payment can be paid again.'], 422);
        }
        if ($p->start_at->lte(now())) {
            return response()->json(['message' => 'That time has already passed. Please book a new schedule.'], 409);
        }

        return DB::transaction(function () use ($p, $user) {
            Provider::where('id', $p->provider_id)->lockForUpdate()->first();

            $covered = ProviderAvailability::where('provider_id', $p->provider_id)
                ->where('status', 'available')
                ->where('start_at', '<=', $p->start_at)
                ->where('end_at', '>=', $p->end_at)
                ->exists();

            $taken = BookingPayment::where('provider_id', $p->provider_id)
                ->where('id', '!=', $p->id)
                ->whereIn('status', ['to_verify', 'verified'])
                ->where('approval_status', '!=', 'declined')
                ->whereNull('cancelled_at')
                ->where('start_at', '<', $p->end_at)
                ->where('end_at', '>', $p->start_at)
                ->exists();

            $held = SlotHold::where('provider_id', $p->provider_id)
                ->where('user_id', '!=', $user->id)
                ->where('expires_at', '>', now())
                ->where('start_at', '<', $p->end_at)
                ->where('end_at', '>', $p->start_at)
                ->exists();

            if (!$covered || $taken || $held) {
                return response()->json([
                    'message' => 'That time is no longer available. Please book a new schedule.',
                ], 409);
            }

            SlotHold::where('user_id', $user->id)->delete();

            $hold = SlotHold::create([
                'provider_id' => $p->provider_id,
                'user_id'     => $user->id,
                'service_id'  => $p->service_id,
                'start_at'    => $p->start_at,
                'end_at'      => $p->end_at,
                'expires_at'  => now()->addMinutes(60),
            ]);

            $psych = $p->psychologist_user_id ? User::find($p->psychologist_user_id) : null;

            return response()->json([
                'service_id'   => $p->service_id,
                'previous' => [
                    'type'             => $p->payment_type,
                    'method_name'      => $p->method_name,
                    'wallet_number'    => $p->wallet_number,
                    'reference_number' => $p->reference_number,
                    'reason'           => $p->reject_reason,
                    'reviewer'         => ($r = $p->reviewed_by ? User::find($p->reviewed_by) : null)
                        ? trim($r->first_name . ' ' . $r->last_name)
                        : null,
                ],
                'psychologist' => $psych
                    ? ['id' => $psych->id, 'name' => trim($psych->first_name . ' ' . $psych->last_name)]
                    : null,
                'hold' => [
                    'id'          => $hold->id,
                    'provider_id' => $hold->provider_id,
                    'date'        => $hold->start_at->format('Y-m-d'),
                    'start_time'  => $hold->start_at->format('H:i'),
                    'end_time'    => $hold->end_at->format('H:i'),
                    'expires_at'  => $hold->expires_at->toIso8601String(),
                ],
            ], 201);
        });
    }

    // ---------------------------------------------------------------
    // CLIENT: BALANCE PAYMENT
    // ---------------------------------------------------------------

    private function latestBalance($paymentId)
    {
        return DB::table('balance_payments')
            ->where('booking_payment_id', $paymentId)
            ->orderByDesc('id')
            ->first();
    }

    private function balanceInfo($row): ?array
    {
        if (!$row) {
            return null;
        }

        return [
            'id'            => $row->id,
            'status'        => $row->status,
            'amount'        => (float) $row->amount,
            'method'        => $row->method_name,
            'account_name'  => $row->account_name,
            'reject_reason' => $row->reject_reason,
            'receipt_url'   => Storage::disk('public')->url($row->receipt_path),
            'created_at'    => Carbon::parse($row->created_at)->toIso8601String(),
            'reviewed_at'   => $row->reviewed_at ? Carbon::parse($row->reviewed_at)->toIso8601String() : null,
        ];
    }

    // GET /api/booking/balance/{id} - data para sa balance page
    public function balanceShow(Request $request, $id)
    {
        $p    = BookingPayment::where('user_id', $request->user()->id)->findOrFail($id);
        $last = $this->latestBalance($p->id);

        $state   = 'due';
        $message = null;

        if ((float) $p->balance <= 0 || ($last && $last->status === 'verified')) {
            $state = 'paid';
        } elseif ($p->status !== 'verified') {
            $state   = 'unavailable';
            $message = 'Your downpayment has to be verified before you can pay the balance.';
        } elseif ($p->cancelled_at || $p->approval_status === 'declined') {
            $state   = 'unavailable';
            $message = 'This appointment is no longer active.';
        } elseif ($last && $last->status === 'to_verify') {
            $state = 'pending';
        }

        $settings = DB::table('payment_settings')->first();
        $psych    = $p->psychologist_user_id ? User::find($p->psychologist_user_id) : null;

        return response()->json([
            'state'   => $state,
            'message' => $message,
            'appointment' => [
                'id'           => $p->id,
                'reference'    => $p->reference,
                'service_name' => $p->assessment_type_name
                    ? ((self::SERVICE_NAMES[$p->service_id] ?? $p->service_id) . ' (' . $p->assessment_type_name . ')')
                    : (self::SERVICE_NAMES[$p->service_id] ?? $p->service_id),
                'psychologist' => $psych ? trim($psych->first_name . ' ' . $psych->last_name) : null,
                'date'         => $p->start_at->format('Y-m-d'),
                'start_time'   => $p->start_at->format('H:i'),
                'end_time'     => $p->end_at->format('H:i'),
                'total_amount' => (float) $p->total_amount,
                'amount_paid'  => (float) $p->amount_paid,
                'balance'      => (float) $p->balance,
            ],
            'last'    => $this->balanceInfo($last),
            'note'    => $settings->note ?? '',
            'methods' => PaymentMethod::where('enabled', true)
                ->orderBy('sort')->orderBy('id')->get()
                ->map(fn ($m) => $m->toArrayPublic())->values(),
        ]);
    }

    // POST /api/booking/balance/{id} - magpadala ng balance receipt
    public function balanceSubmit(Request $request, $id)
    {
        $data = $request->validate([
            'method_id'    => ['required', 'integer'],
            'account_name' => ['required', 'string', 'min:2', 'max:120'],
            'receipt'      => ['required', 'string'],
        ]);

        if (!preg_match('/^data:image\/jpeg;base64,/', $data['receipt'])) {
            return response()->json(['message' => 'Please upload a JPG or PNG screenshot.'], 422);
        }
        $binary = base64_decode(substr($data['receipt'], strpos($data['receipt'], ',') + 1), true);
        if ($binary === false || strlen($binary) > 3 * 1024 * 1024 || @getimagesizefromstring($binary) === false) {
            return response()->json(['message' => 'That receipt image could not be used. Try a smaller one.'], 422);
        }

        $userId = $request->user()->id;

        return DB::transaction(function () use ($id, $userId, $data, $binary) {
            $p = BookingPayment::where('user_id', $userId)->lockForUpdate()->findOrFail($id);

            if ($p->status !== 'verified' || (float) $p->balance <= 0) {
                return response()->json(['message' => 'There is no balance to pay for this appointment.'], 422);
            }
            if ($p->cancelled_at || $p->approval_status === 'declined') {
                return response()->json(['message' => 'This appointment is no longer active.'], 422);
            }

            $last = $this->latestBalance($p->id);
            if ($last && in_array($last->status, ['to_verify', 'verified'], true)) {
                return response()->json(['message' => 'Your balance receipt is already being reviewed.'], 422);
            }

            $method = PaymentMethod::where('enabled', true)->find($data['method_id']);
            if (!$method) {
                return response()->json(['message' => 'That payment method is not available.'], 422);
            }

            $path = 'receipts/balance-' . Str::random(40) . '.jpg';
            Storage::disk('public')->put($path, $binary);

            $rowId = DB::table('balance_payments')->insertGetId([
                'booking_payment_id' => $p->id,
                'user_id'            => $userId,
                'amount'             => $p->balance, // laging galing sa server, hindi sa client
                'method_name'        => $method->name,
                'account_name'       => trim($data['account_name']),
                'receipt_path'       => $path,
                'status'             => 'to_verify',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            return response()->json([
                'balance_payment' => $this->balanceInfo(DB::table('balance_payments')->find($rowId)),
            ], 201);
        });
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

    private function format(BookingPayment $p, $profiles, $users, $balances = null): array
    {
        $profile = $profiles[$p->user_id] ?? null;
        $u       = $users[$p->user_id] ?? null;

        // Pinakabagong balance receipt (galing sa preloaded list kung meron, kung hindi mag-query)
        $bal         = $balances !== null ? ($balances[$p->id] ?? null) : $this->latestBalance($p->id);
        $balancePaid = $bal && $bal->status === 'verified';

        return [
            'id'               => $p->id,
            'reference'        => $p->reference,
            'client_name'      => $profile->full_name ?? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
            'client_email'     => $profile->email ?? ($u->email ?? ''),
            'client_mobile'    => $profile->mobile ?? ($u->phone ?? ''),
            'service_id'       => $p->service_id,
            'service_name'     => $p->assessment_type_name ? ((self::SERVICE_NAMES[$p->service_id] ?? $p->service_id) . ' (' . $p->assessment_type_name . ')') : (self::SERVICE_NAMES[$p->service_id] ?? $p->service_id),
            'assessment_type'  => $p->assessment_type_name,
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

            // --- Balance ---
            'balance_payment'    => $this->balanceInfo($bal),
            'balance_paid'       => (bool) $balancePaid,
            // Ang orihinal na downpayment (kapag bayad na ang balance, full na ang amount_paid)
            'downpayment_amount' => $balancePaid
                ? round((float) $p->total_amount - (float) $bal->amount, 2)
                : (float) $p->amount_paid,
        ];
    }

    // GET /api/admin/payments  |  GET /api/affiliate/payments
    public function index(Request $request)
    {
        $rows = $this->scoped($request->user())->orderByDesc('created_at')->limit(300)->get();
        // Itago ang na-reject na payment kung may mas bago nang bayad ang parehong client para sa parehong oras
        $rows = $rows->reject(function ($p) use ($rows) {
            return $p->status === 'rejected'
                && $rows->contains(fn ($o) => $o->id > $p->id
                    && (int) $o->user_id === (int) $p->user_id
                    && (int) $o->provider_id === (int) $p->provider_id
                    && $o->start_at->equalTo($p->start_at));
        })->values();

        $ids      = $rows->pluck('user_id')->unique();
        $profiles = ClientProfile::whereIn('user_id', $ids)->get()->keyBy('user_id');
        $users    = User::whereIn('id', $ids)->get()->keyBy('id');

        // Pinakabagong balance receipt ng bawat payment
        $balances = DB::table('balance_payments')
            ->whereIn('booking_payment_id', $rows->pluck('id'))
            ->orderBy('id')->get()
            ->groupBy('booking_payment_id')
            ->map(fn ($g) => $g->last());

        $active = $rows->whereIn('status', ['to_verify', 'verified']);

        return response()->json([
            'payments' => $rows->map(fn ($p) => $this->format($p, $profiles, $users, $balances))->values(),
            'stats'    => [
                'verified_total' => (float) $rows->where('status', 'verified')->sum('amount_paid'),
                // kasama ang balance receipts na naghihintay ng review
                'to_verify'      => $rows->where('status', 'to_verify')->count()
                    + $balances->where('status', 'to_verify')->count(),
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
            $reason = $request->validate(
                ['reason' => ['required', 'string', 'min:3', 'max:255']],
                ['reason.required' => 'Please write or choose a message for the client.']
            )['reason'];
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

    // Admin / Affiliate: i-verify o i-reject ang balance receipt
    private function balanceReview(Request $request, $id, string $status)
    {
        $user = $request->user();
        $p    = $this->scoped($user)->findOrFail($id);

        $bal = DB::table('balance_payments')
            ->where('booking_payment_id', $p->id)
            ->where('status', 'to_verify')
            ->orderByDesc('id')
            ->first();

        if (!$bal) {
            return response()->json(['message' => 'There is no balance receipt waiting for review.'], 422);
        }

        $reason = null;
        if ($status === 'rejected') {
            $reason = $request->validate(
                ['reason' => ['required', 'string', 'min:3', 'max:255']],
                ['reason.required' => 'Please write or choose a message for the client.']
            )['reason'];
        }

        DB::transaction(function () use ($p, $bal, $status, $reason, $user) {
            DB::table('balance_payments')->where('id', $bal->id)->update([
                'status'        => $status,
                'reject_reason' => $reason,
                'reviewed_by'   => $user->id,
                'reviewed_at'   => now(),
                'updated_at'    => now(),
            ]);

            // Verified: ang downpayment ay nagiging full payment na
            if ($status === 'verified') {
                $newPaid = round((float) $p->amount_paid + (float) $bal->amount, 2);
                $p->forceFill([
                    'payment_type' => 'full',
                    'amount_paid'  => $newPaid,
                    'balance'      => max(0, round((float) $p->total_amount - $newPaid, 2)),
                ])->save();
            }
        });

        $p->refresh();
        $profiles = ClientProfile::where('user_id', $p->user_id)->get()->keyBy('user_id');
        $users    = User::where('id', $p->user_id)->get()->keyBy('id');

        return response()->json(['payment' => $this->format($p, $profiles, $users)]);
    }

    // POST /api/{admin|affiliate}/payments/{id}/balance/verify
    public function balanceVerify(Request $request, $id)
    {
        return $this->balanceReview($request, $id, 'verified');
    }

    // POST /api/{admin|affiliate}/payments/{id}/balance/reject
    public function balanceReject(Request $request, $id)
    {
        return $this->balanceReview($request, $id, 'rejected');
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