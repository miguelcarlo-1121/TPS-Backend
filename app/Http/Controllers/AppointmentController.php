<?php

namespace App\Http\Controllers;

use App\Models\BookingPayment;
use App\Models\ClientProfile;
use App\Models\ConsentForm;
use App\Models\User;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    private const SERVICE_NAMES = [
        'psychotherapy' => 'Psychotherapy',
        'aba'           => 'ABA Therapy',
        'assessment'    => 'Assessment',
    ];

    /**
     * Psychotherapy: psychologist na pinili lang ang makakakita.
     * ABA / Assessment: lahat ng ADMIN.
     * Hindi kasama ang rejected na payment.
     */
    private function scoped(User $user)
    {
        $q = BookingPayment::query()->where('status', '!=', 'rejected');

        if ($user->role === 'AFFILIATE') {
            return $q->where('service_id', 'psychotherapy')
                ->where('psychologist_user_id', $user->id);
        }

        return $q->where(function ($w) use ($user) {
            $w->where('service_id', '!=', 'psychotherapy')
              ->orWhere('psychologist_user_id', $user->id);
        });
    }

    // verify | pending | approved | completed | declined
    private function state(BookingPayment $p): string
    {
          if ($p->cancelled_at) {
      return response()->json(['message' => 'This appointment was cancelled by the client.'], 422);
  }
        if ($p->status === 'to_verify') return 'verify';
        if ($p->approval_status === 'declined') return 'declined';
        if ($p->completed_at) return 'completed';
        if ($p->approval_status === 'approved') return 'approved';
        return 'pending';
    }

    // not_required (ABA/Assessment) | pending | submitted
    private function consentStatus(BookingPayment $p, $consents): string
    {
        if ($p->service_id !== 'psychotherapy') return 'not_required';
        return $consents->has($p->id) ? 'submitted' : 'pending';
    }

    private function format(BookingPayment $p, $profiles, $users, $consents): array
    {
        $profile = $profiles[$p->user_id] ?? null;
        $u       = $users[$p->user_id] ?? null;

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
            'service_name'       => self::SERVICE_NAMES[$p->service_id] ?? $p->service_id,
            'date'               => $p->start_at->format('Y-m-d'),
            'start_time'         => $p->start_at->format('H:i'),
            'end_time'           => $p->end_at->format('H:i'),
            'payment_type'       => $p->payment_type,
            'method'             => $p->method_name,
            'total_amount'       => $p->total_amount,
            'amount_paid'        => $p->amount_paid,
            'balance'            => $p->balance,
            'decline_reason'     => $p->decline_reason,
            'consent_status'     => $this->consentStatus($p, $consents),
        ];
    }

    private function lookups($rows): array
    {
        $ids = $rows->pluck('user_id')->unique();

        return [
            ClientProfile::whereIn('user_id', $ids)->get()->keyBy('user_id'),
            User::whereIn('id', $ids)->get()->keyBy('id'),
            ConsentForm::whereIn('booking_payment_id', $rows->pluck('id'))->pluck('submitted_at', 'booking_payment_id'),
        ];
    }

    private function one(BookingPayment $p): array
    {
        [$profiles, $users, $consents] = $this->lookups(collect([$p]));

        return $this->format($p, $profiles, $users, $consents);
    }

    // GET /api/{admin|affiliate}/appointments
    public function index(Request $request)
    {
        $rows = $this->scoped($request->user())
            ->orderBy('start_at')
            ->limit(500)
            ->get();

        [$profiles, $users, $consents] = $this->lookups($rows);

        return response()->json([
            'appointments' => $rows->map(fn ($p) => $this->format($p, $profiles, $users, $consents))->values(),
        ]);
    }

    // GET /api/{admin|affiliate}/appointments/{id}/consent
    public function consent(Request $request, $id)
    {
        $p = $this->scoped($request->user())->findOrFail($id);

        if ($p->service_id !== 'psychotherapy') {
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
            'guardian'     => $c->is_minor
                ? ['name' => $c->guardian_name, 'relation' => $c->guardian_relation]
                : null,
            'intake'       => $c->intake,
            'agreements'   => $c->consent,
            'dsm5'         => $c->dsm5,
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