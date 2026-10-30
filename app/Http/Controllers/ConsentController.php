<?php

namespace App\Http\Controllers;

use App\Models\BookingPayment;
use App\Models\ClientProfile;
use App\Models\ConsentForm;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ConsentController extends Controller
{
    private const NAME_RE  = '/^[\pL][\pL\s.\'’-]*$/u';
    private const PHONE_RE = '/^09\d{9}$/';

    private const SERVICE_NAMES = [
        'psychotherapy' => 'Psychotherapy',
        'aba'           => 'ABA Therapy',
        'assessment'    => 'Assessment',
    ];

    private function latest(User $user): ?BookingPayment
    {
        return BookingPayment::where('user_id', $user->id)->latest('id')->first();
    }

    private function approved(BookingPayment $p): bool
    {
        return $p->status !== 'rejected' && $p->approval_status === 'approved';
    }

    // GET /api/booking/consent
    public function show(Request $request)
    {
        $user = $request->user();
        $p    = $this->latest($user);

        if (!$p) {
            return response()->json(['payment' => null]);
        }

        $profile = ClientProfile::where('user_id', $user->id)->first();
        $done    = ConsentForm::where('booking_payment_id', $p->id)->first();
        $psych   = $p->service_id === 'psychotherapy' && $p->psychologist_user_id
            ? User::find($p->psychologist_user_id)
            : null;

        return response()->json([
            'payment' => [
                'id'           => $p->id,
                'reference'    => $p->reference,
                'service_id'   => $p->service_id,
                'service_name' => self::SERVICE_NAMES[$p->service_id] ?? $p->service_id,
                'required'     => $p->service_id === 'psychotherapy',
                'approved'     => $this->approved($p),
                'submitted'    => (bool) $done,
                'submitted_at' => $done?->submitted_at?->toIso8601String(),
                'psychologist' => $psych ? trim($psych->first_name . ' ' . $psych->last_name) : null,
                'date'         => $p->start_at->format('Y-m-d'),
                'start_time'   => $p->start_at->format('H:i'),
                'end_time'     => $p->end_at->format('H:i'),
                'total_amount' => $p->total_amount,
            ],
            'prefill' => [
                'full_name'          => $profile->full_name ?? trim($user->first_name . ' ' . $user->last_name),
                'birthdate'          => $profile && $profile->birthdate ? $profile->birthdate->format('Y-m-d') : '',
                'email'              => $profile->email ?? '',
                'mobile'             => $profile->mobile ?? '',
                'emergency_name'     => $profile->emergency_name ?? '',
                'emergency_relation' => $profile->emergency_relation ?? '',
                'emergency_phone'    => $profile->emergency_phone ?? '',
            ],
        ]);
    }

    // POST /api/booking/consent
    public function store(Request $request)
    {
        $user = $request->user();
        $p    = $this->latest($user);

        if (!$p) {
            return response()->json(['message' => 'No booking found.'], 404);
        }
        if ($p->service_id !== 'psychotherapy') {
            return response()->json(['message' => 'The consent form is only for Psychotherapy.'], 422);
        }
        if (!$this->approved($p)) {
            return response()->json(['message' => 'Your appointment is not approved yet.'], 422);
        }
        if (ConsentForm::where('booking_payment_id', $p->id)->exists()) {
            return response()->json(['message' => 'You already submitted your consent form.'], 422);
        }

        $name = ['required', 'string', 'min:2', 'max:150', 'regex:' . self::NAME_RE];
        $rel  = ['required', 'string', 'min:2', 'max:60', 'regex:' . self::NAME_RE];
        $tel  = ['required', 'regex:' . self::PHONE_RE];

        $data = $request->validate([
            'intake.full_name'      => $name,
            'intake.nickname'       => ['nullable', 'string', 'max:60'],
            'intake.gender'         => ['nullable', 'string', 'max:60'],
            'intake.civil_status'   => ['nullable', 'string', 'max:30'],
            'intake.birthdate'      => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1899-12-31'],
            'intake.address'        => ['required', 'string', 'min:5', 'max:255'],
            'intake.mobile'         => $tel,
            'intake.email'          => ['required', 'email', 'max:190'],
            'intake.occupation'     => ['nullable', 'string', 'max:120'],
            'intake.heard_about'    => ['nullable', 'string', 'max:190'],

            'intake.emergency'              => ['required', 'array', 'min:1', 'max:2'],
            'intake.emergency.*.name'       => $name,
            'intake.emergency.*.relation'   => $rel,
            'intake.emergency.*.mobile'     => $tel,

            'intake.reason'         => ['required', 'string', 'min:5', 'max:2000'],
            'intake.duration_unit'  => ['required', 'in:days,weeks,months,years'],
            'intake.goals'          => ['required', 'string', 'min:3', 'max:2000'],

            'intake.prev_therapy'          => ['required', 'boolean'],
            'intake.prev_therapy_details'  => ['nullable', 'string', 'max:500'],
            'intake.diagnosed'             => ['required', 'boolean'],
            'intake.diagnosed_details'     => ['nullable', 'string', 'max:500'],
            'intake.medications'           => ['required', 'boolean'],
            'intake.medications_details'   => ['nullable', 'string', 'max:500'],
            'intake.medical'               => ['required', 'boolean'],
            'intake.medical_details'       => ['nullable', 'string', 'max:500'],

            'consent.read'            => ['accepted'],
            'consent.confidentiality' => ['accepted'],
            'consent.crisis'          => ['accepted'],
            'consent.fees'            => ['accepted'],
            'consent.privacy'         => ['accepted'],

            'dsm5'   => ['required', 'array', 'size:23'],
            'dsm5.*' => ['required', 'integer', 'between:0,4'],

            'sign.name'    => $name,
            'sign.certify' => ['accepted'],

            'guardian.name'     => ['nullable', 'string', 'max:150'],
            'guardian.relation' => ['nullable', 'string', 'max:60'],
        ], [
            'intake.full_name.regex' => 'Full name must not contain numbers.',
            'intake.mobile.regex'    => 'Mobile number must be 11 digits (e.g. 09123456789).',
            'sign.name.regex'        => 'Signature name must not contain numbers.',
            'dsm5.size'              => 'Please answer all 23 symptom questions.',
        ]);

        // "Yes" na walang detalye
        $errors = [];
        foreach (['prev_therapy', 'diagnosed', 'medications', 'medical'] as $k) {
            if (!empty($data['intake'][$k]) && trim((string) ($data['intake'][$k . '_details'] ?? '')) === '') {
                $errors["intake.{$k}_details"] = 'Please add the details.';
            }
        }

        // Under 18: kailangan ang magulang/guardian
        $age     = Carbon::parse($data['intake']['birthdate'])->age;
        $isMinor = $age < 18;
        $gName   = trim((string) ($data['guardian']['name'] ?? ''));
        $gRel    = trim((string) ($data['guardian']['relation'] ?? ''));

        if ($isMinor) {
            if (!preg_match(self::NAME_RE, $gName)) $errors['guardian.name'] = 'Parent / guardian name is required.';
            if (!preg_match(self::NAME_RE, $gRel))  $errors['guardian.relation'] = 'Relationship to the minor is required.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $data['intake']['age'] = $age;
        $data['intake']['full_name'] = trim($data['intake']['full_name']);
        $data['intake']['email']     = strtolower(trim($data['intake']['email']));

        ConsentForm::create([
            'booking_payment_id' => $p->id,
            'user_id'            => $user->id,
            'intake'             => $data['intake'],
            'consent'            => array_merge(
                array_map(fn () => true, $data['consent']),
                ['version' => 'psychotherapy-consent-v1']
            ),
            'dsm5'               => array_map('intval', array_values($data['dsm5'])),
            'signed_name'        => trim($data['sign']['name']),
            'signed_date'        => now()->toDateString(),
            'is_minor'           => $isMinor,
            'guardian_name'      => $isMinor ? $gName : null,
            'guardian_relation'  => $isMinor ? $gRel : null,
            'submitted_at'       => now(),
        ]);

        return response()->json(['message' => 'Consent form submitted.'], 201);
    }
}