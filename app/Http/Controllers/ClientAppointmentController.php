<?php

namespace App\Http\Controllers;

use App\Models\BookingPayment;
use App\Models\SlotHold;
use App\Models\User;
use App\Models\ConsentForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientAppointmentController extends Controller
{
    private const SERVICE_NAMES = [
        'psychotherapy' => 'Psychotherapy',
        'aba'           => 'ABA Therapy',
        'assessment'    => 'Assessment',
    ];

    // upcoming | completed | cancelled
    private function tab(BookingPayment $p): string
    {
        if ($p->cancelled_at || $p->status === 'rejected' || $p->approval_status === 'declined') {
            return 'cancelled';
        }
        if ($p->completed_at) {
            return 'completed';
        }
        if ($p->end_at->isPast()) {
            return $p->approval_status === 'approved' ? 'completed' : 'cancelled'; // lumipas na at hindi na-approve
        }
        return 'upcoming';
    }

    private function label(BookingPayment $p): string
    {
        if ($p->cancelled_at)                    return 'Cancelled';
        if ($p->status === 'rejected')           return 'Payment not verified';
        if ($p->approval_status === 'declined')  return 'Declined';
        if ($p->completed_at)                    return 'Completed';
        if ($p->end_at->isPast())                return $p->approval_status === 'approved' ? 'Completed' : 'Expired';
        if ($p->status === 'to_verify')          return 'Pending payment verification';
        if ($p->approval_status === 'approved')  return 'Confirmed';
        return 'Pending approval';
    }

    // Pwede lang mag-cancel/reschedule kung upcoming pa at hindi pa nagsisimula
    private function canChange(BookingPayment $p): bool
    {
        return $this->tab($p) === 'upcoming' && $p->start_at->isFuture();
    }

    private function format(BookingPayment $p, $psychs, $consentIds): array
{
    $psych = $p->psychologist_user_id ? ($psychs[$p->psychologist_user_id] ?? null) : null;

    return [
        'id'                   => $p->id,
        'reference'            => $p->reference,
        'tab'                  => $this->tab($p),
        'label'                => $this->label($p),
        'service_id'           => $p->service_id,
        'service_name'         => self::SERVICE_NAMES[$p->service_id] ?? $p->service_id,
        'provider_id'          => $p->provider_id,
        'psychologist_user_id' => $p->psychologist_user_id,
        'psychologist'         => $psych ? trim($psych->first_name . ' ' . $psych->last_name) : null,
        'date'                 => $p->start_at->format('Y-m-d'),
        'start_time'           => $p->start_at->format('H:i'),
        'end_time'             => $p->end_at->format('H:i'),
        'payment_type'         => $p->payment_type,
        'total_amount'         => $p->total_amount,
        'amount_paid'          => $p->amount_paid,
        'balance'              => $p->balance,
        'consent_status'       => $p->service_id !== 'psychotherapy'
            ? 'not_required'
            : ($consentIds->contains($p->id) ? 'submitted' : 'pending'),
        'reschedule_count'     => $p->reschedule_count,
        'cancel_reason'        => $p->cancel_reason,
        'can_reschedule'       => $this->canChange($p),
        'can_cancel'           => $this->canChange($p),
    ];
}

private function consentLookup($rows)
{
    return ConsentForm::whereIn('booking_payment_id', $rows->pluck('id'))->pluck('booking_payment_id');
}
    private function psychLookup($rows)
    {
        return User::whereIn('id', $rows->pluck('psychologist_user_id')->filter()->unique())
            ->get()->keyBy('id');
    }

    // GET /api/my-appointments
    public function index(Request $request)
    {
        $rows = BookingPayment::where('user_id', $request->user()->id)
            ->orderByDesc('start_at')
            ->limit(200)
            ->get();


        $psychs   = $this->psychLookup($rows);
        $consents = $this->consentLookup($rows);

        return response()->json([
            'appointments' => $rows->map(fn ($p) => $this->format($p, $psychs, $consents))->values(),
        ]);
    }

    // POST /api/my-appointments/{id}/cancel
    public function cancel(Request $request, $id)
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        return DB::transaction(function () use ($request, $id, $reason) {
            $p = BookingPayment::where('user_id', $request->user()->id)
                ->lockForUpdate()->findOrFail($id);

            if (!$this->canChange($p)) {
                return response()->json(['message' => 'This appointment can no longer be cancelled.'], 422);
            }

            $p->forceFill([
                'cancelled_at'  => now(),
                'cancel_reason' => $reason,
            ])->save();

              return response()->json(['appointment' => $this->format($p, $this->psychLookup(collect([$p])), $this->consentLookup(collect([$p])))]);
        });
    }

    // POST /api/my-appointments/{id}/reschedule   body: { hold_id }
    public function reschedule(Request $request, $id)
    {
        $data = $request->validate(['hold_id' => ['required', 'integer']]);
        $user = $request->user();

        return DB::transaction(function () use ($data, $user, $id) {
            $p = BookingPayment::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);

            if (!$this->canChange($p)) {
                return response()->json(['message' => 'This appointment can no longer be rescheduled.'], 422);
            }

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

            if ($hold->service_id !== $p->service_id) {
                return response()->json(['message' => 'The new time must be for the same service.'], 422);
            }

            // Psychotherapy: kailangang parehong psychologist
            if ($p->service_id === 'psychotherapy' && (int) $hold->provider_id !== (int) $p->provider_id) {
                return response()->json(['message' => 'Please choose a time with the same psychologist.'], 422);
            }

            $p->forceFill([
                'original_start_at' => $p->original_start_at ?? $p->start_at,
                'start_at'          => $hold->start_at,
                'end_at'            => $hold->end_at,
                'provider_id'       => $hold->provider_id, // ABA/Assessment: pwedeng ibang admin
                'reschedule_count'  => $p->reschedule_count + 1,
                'rescheduled_at'    => now(),
            ])->save();

            $hold->delete();

              return response()->json(['appointment' => $this->format($p, $this->psychLookup(collect([$p])), $this->consentLookup(collect([$p])))]);
        });
    }
}