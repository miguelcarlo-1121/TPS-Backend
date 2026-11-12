<?php

namespace App\Http\Controllers;

use App\Models\BookingPayment;
use App\Models\ClientProfile;
use App\Models\ConsentForm;
use App\Models\Provider;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppointmentController extends Controller
{
    private const SERVICE_NAMES = [
        'psychotherapy' => 'Psychotherapy',
        'aba'           => 'ABA Therapy',
        'assessment'    => 'Assessment',
    ];

    private function psychotherapySlugs(): array
    {
        $customSlugs = DB::table('services')
            ->get(['slug', 'name'])
            ->filter(fn ($service) => str_contains(
                strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $service->name)),
                'psychotherapy'
            ))
            ->pluck('slug')
            ->all();

        return array_values(array_unique(array_merge(['psychotherapy'], $customSlugs)));
    }

    private function isPsychotherapy(BookingPayment $p, $serviceNames): bool
    {
        $name = $serviceNames[$p->service_id] ?? self::SERVICE_NAMES[$p->service_id] ?? '';

        return $p->service_id === 'psychotherapy'
            || str_contains(strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $name)), 'psychotherapy');
    }

    /**
     * Psychotherapy: psychologist na pinili lang ang makakakita.
     * ABA / Assessment: ADMIN lang na naka-assign (provider_id ng booking).
     * Hindi kasama ang rejected na payment.
     */
    private function scoped(User $user)
    {
        $q = BookingPayment::query()->where('status', '!=', 'rejected');
        $psychotherapySlugs = $this->psychotherapySlugs();

        if ($user->role === 'AFFILIATE') {
            return $q->whereIn('service_id', $psychotherapySlugs)
                ->where('psychologist_user_id', $user->id);
        }

        // ADMIN: psychotherapy na sila ang pinili, at ABA/Assessment na naka-assign
        // sa KANYANG provider_id lang. Hindi niya makikita o magagalaw ang booking ng ibang Admin.
        $providerId = Provider::where('user_id', $user->id)->value('id');

        return $q->where(function ($w) use ($user, $providerId, $psychotherapySlugs) {
            $w->where(function ($x) use ($user, $psychotherapySlugs) {
                $x->whereIn('service_id', $psychotherapySlugs)
                  ->where('psychologist_user_id', $user->id);
            })->orWhere(function ($x) use ($providerId, $psychotherapySlugs) {
                $x->whereNotIn('service_id', $psychotherapySlugs)
                  ->where('provider_id', $providerId ?? 0);
            });
        });
    }

    // verify | pending | approved | completed | declined
    private function state(BookingPayment $p): string
{
    if ($p->cancelled_at)                   return 'cancelled';
    if ($p->status === 'to_verify')         return 'verify';
    if ($p->approval_status === 'declined') return 'declined';
    if ($p->completed_at)                   return 'completed';
    if ($p->approval_status === 'approved') return 'approved';
    return 'pending';
}
    private function finished($q)
{
    return $q->where(function ($w) {
        $w->whereNotNull('completed_at')
          ->orWhereNotNull('cancelled_at')
          ->orWhere('approval_status', 'declined');
    });
}


private function unfinished($q)
{
    return $q->whereNull('completed_at')
        ->whereNull('cancelled_at')
        ->where(function ($w) {
            $w->whereNull('approval_status')->orWhere('approval_status', '!=', 'declined');
        });
}
    // not_required (Assessment) | pending | submitted
    private function isAba(BookingPayment $p, $serviceNames): bool
    {
        $name = $serviceNames[$p->service_id] ?? self::SERVICE_NAMES[$p->service_id] ?? '';

        return $p->service_id === 'aba'
            || strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $name)) === 'abatherapy';
    }

    private function consentStatus(BookingPayment $p, $consents, $serviceNames): string
    {
        if (!$this->isPsychotherapy($p, $serviceNames) && !$this->isAba($p, $serviceNames)) return 'not_required';
        return $consents->has($p->id) ? 'submitted' : 'pending';
    }

    private function format(BookingPayment $p, $profiles, $users, $consents, $serviceNames): array
    {
        $profile = $profiles['booking'][$p->id] ?? $profiles['user'][$p->user_id] ?? null;
        $u       = $users[$p->user_id] ?? null;
        $serviceName = self::SERVICE_NAMES[$p->service_id] ?? $serviceNames[$p->service_id] ?? $p->service_id;

        return [
            'id'                 => $p->id,
            'reference'          => $p->reference,
            'state'              => $this->state($p),
            'client_name'        => $profile->full_name ?? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
            'client_email'       => $profile->email ?? ($u->email ?? ''),
            'client_mobile'      => $profile->mobile ?? ($u->phone ?? ''),
            'birthdate'          => $profile && $profile->birthdate ? $profile->birthdate->format('Y-m-d') : null,
            'emergency_name'     => $profile->emergency_name ?? '',
            'emergency_relation' => $profile->emergency_relation ?? '',
            'emergency_phone'    => $profile->emergency_phone ?? '',
            'service_id'         => $p->service_id,
            'service_name'       => $p->assessment_type_name ? ($serviceName . ' (' . $p->assessment_type_name . ')') : $serviceName,
            'consent_type'       => $this->isAba($p, $serviceNames) ? 'aba' : ($this->isPsychotherapy($p, $serviceNames) ? 'psychotherapy' : null),
            'assessment_type'    => $p->assessment_type_name,
            'date'               => $p->start_at->format('Y-m-d'),
            'start_time'         => $p->start_at->format('H:i'),
            'end_time'           => $p->end_at->format('H:i'),
            'payment_type'       => $p->payment_type,
            'method'             => $p->method_name,
            'total_amount'       => $p->total_amount,
            'amount_paid'        => $p->amount_paid,
            'balance'            => $p->balance,
            'decline_reason'     => $p->decline_reason,
            'completed_at'       => $p->completed_at ? Carbon::parse($p->completed_at)->toIso8601String() : null,
            'cancelled_at'       => $p->cancelled_at ? Carbon::parse($p->cancelled_at)->toIso8601String() : null,
            'consent_status'     => $this->consentStatus($p, $consents, $serviceNames),
        ];
    }

    private function lookups($rows): array
    {
        $ids = $rows->pluck('user_id')->unique();

        return [
            [
                'booking' => ClientProfile::whereIn('booking_payment_id', $rows->pluck('id'))
                    ->get()
                    ->keyBy('booking_payment_id'),
                'user' => ClientProfile::whereIn('user_id', $ids)
                    ->whereNull('booking_payment_id')
                    ->orderByDesc('id')
                    ->get()
                    ->keyBy('user_id'),
            ],
            User::whereIn('id', $ids)->get()->keyBy('id'),
            ConsentForm::whereIn('booking_payment_id', $rows->pluck('id'))->pluck('submitted_at', 'booking_payment_id'),
            DB::table('services')->whereIn('slug', $rows->pluck('service_id')->unique())->pluck('name', 'slug'),
        ];
    }

    private function one(BookingPayment $p): array
    {
        [$profiles, $users, $consents, $serviceNames] = $this->lookups(collect([$p]));

        return $this->format($p, $profiles, $users, $consents, $serviceNames);
    }

    public function index(Request $request)
{
    BookingPayment::completeEnded();

    $base = $this->scoped($request->user());

    $rows = $this->unfinished(clone $base)
        ->orderBy('start_at')
        ->limit(500)
        ->get();

    [$profiles, $users, $consents, $serviceNames] = $this->lookups($rows);

    return response()->json([
        'appointments'  => $rows->map(fn ($p) => $this->format($p, $profiles, $users, $consents, $serviceNames))->values(),
        'history_count' => $this->finished(clone $base)->count(),
    ]);
}

    // GET /api/{admin|affiliate}/appointments/history  (completed, declined, cancelled)
    public function history(Request $request)
    {
        BookingPayment::completeEnded();

        $rows = $this->finished($this->scoped($request->user()))
            ->orderByDesc('start_at')
            ->limit(300)
            ->get();

        [$profiles, $users, $consents, $serviceNames] = $this->lookups($rows);

        return response()->json([
            'appointments' => $rows->map(fn ($p) => $this->format($p, $profiles, $users, $consents, $serviceNames))->values(),
        ]);
    }

    // GET /api/{admin|affiliate}/appointments/{id}/consent
    public function consent(Request $request, $id)
    {
        $p = $this->scoped($request->user())->findOrFail($id);

        $serviceName = DB::table('services')->where('slug', $p->service_id)->value('name');
        $isAba = $this->isAba($p, [$p->service_id => $serviceName]);
        if (!$this->isPsychotherapy($p, [$p->service_id => $serviceName]) && !$isAba) {
            return response()->json(['message' => 'No consent form is required for this service.'], 404);
        }

        $c = ConsentForm::where('booking_payment_id', $p->id)->first();

        if (!$c) {
            return response()->json(['consent' => null]);
        }

        return response()->json(['consent' => [
            'submitted_at' => $c->submitted_at->toIso8601String(),
            'signed_name'  => $c->signed_name,
            'signed_date'  => $c->signed_date->format('Y-m-d'),
            'is_minor'     => $c->is_minor,
            'guardian'     => ($c->is_minor || $isAba)
                ? ['name' => $c->guardian_name, 'relation' => $c->guardian_relation]
                : null,
            'intake'                  => $c->intake,
            'agreements'              => $c->consent,
            'signature_data'          => $c->signature_data ?? ($c->consent['signature_data'] ?? null),
            'guardian_signature_data' => $c->guardian_signature_data ?? ($c->consent['guardian_signature_data'] ?? null),
            'dsm5'                    => $c->dsm5,
        ]]);
    }

    // POST .../appointments/{id}/approve
    public function approve(Request $request, $id)
    {
        $p = $this->scoped($request->user())->findOrFail($id);

        if ($p->status !== 'verified') {
            return response()->json(['message' => 'Verify the payment first before approving.'], 422);
        }
        if ($p->approval_status !== 'pending') {
            return response()->json(['message' => 'This appointment was already reviewed.'], 422);
        }

        $p->forceFill([
            'approval_status' => 'approved',
            'approved_by'     => $request->user()->id,
            'approved_at'     => now(),
            'decline_reason'  => null,
        ])->save();

        return response()->json(['appointment' => $this->one($p)]);
    }

    // POST .../appointments/{id}/decline
    public function decline(Request $request, $id)
    {
        $p = $this->scoped($request->user())->findOrFail($id);

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        if ($p->status !== 'verified') {
            return response()->json(['message' => 'Verify or reject the payment on the Payment page first.'], 422);
        }
        if ($p->approval_status !== 'pending') {
            return response()->json(['message' => 'This appointment was already reviewed.'], 422);
        }

        $p->forceFill([
            'approval_status' => 'declined',
            'approved_by'     => $request->user()->id,
            'approved_at'     => now(),
            'decline_reason'  => $reason,
        ])->save();

        return response()->json(['appointment' => $this->one($p)]);
    }

    // POST .../appointments/{id}/complete
    public function complete(Request $request, $id)
    {
        $p = $this->scoped($request->user())->findOrFail($id);

        if ($p->approval_status !== 'approved') {
            return response()->json(['message' => 'Only approved appointments can be marked as done.'], 422);
        }
        if ($p->completed_at) {
            return response()->json(['message' => 'Already marked as done.'], 422);
        }
        if ($p->start_at->isFuture()) {
            return response()->json(['message' => 'The session has not started yet.'], 422);
        }

        $p->forceFill(['completed_at' => now()])->save();

        return response()->json(['appointment' => $this->one($p)]);
    }
}