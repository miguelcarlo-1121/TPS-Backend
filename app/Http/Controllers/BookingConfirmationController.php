<?php

namespace App\Http\Controllers;

use App\Models\BookingPayment;
use App\Models\ClientProfile;
use App\Models\ConsentForm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class BookingConfirmationController extends Controller
{
    private const SERVICE_NAMES = [
        'psychotherapy' => 'Psychotherapy',
        'aba'           => 'ABA Therapy',
        'assessment'    => 'Assessment',
    ];

    // Kapareho ng logic ng latest() sa BookingPaymentController
    private function stage(BookingPayment $p): string
    {   
        if ($p->cancelled_at) return 'CANCELLED';
        if ($p->status === 'rejected' || $p->approval_status === 'declined') return 'REJECTED';
        if ($p->status === 'to_verify')                                     return 'PAYMENT_PENDING';
        if ($p->approval_status === 'approved')                             return 'CONFIRMED';
        return 'PSYCHOLOGIST_PENDING';
    }

    // Consent: hinahanap ang column na nag-uugnay sa booking. Kung wala, 'false'.
    private function consentDone(BookingPayment $p): bool
    {
        return in_array($p->service_id, ['psychotherapy', 'aba'], true)
            && ConsentForm::where('booking_payment_id', $p->id)->exists();
    }

    // GET /api/booking/confirmation?reference=TF-XXXX
    public function show(Request $request)
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:60']]);
        $user = $request->user();

        // Ang may-ari lang ang makakakita
        $p = BookingPayment::where('reference', $data['reference'])
            ->where('user_id', $user->id)
            ->first();

        if (!$p) {
            return response()->json(['message' => 'Appointment not found.'], 404);
        }

        $profile = ClientProfile::where('user_id', $p->user_id)->first();

        $psych = $p->service_id === 'psychotherapy' && $p->psychologist_user_id
            ? User::find($p->psychologist_user_id)
            : null;

        $serviceName = self::SERVICE_NAMES[$p->service_id] ?? $p->service_id;
        $serviceFullName = $p->assessment_type_name
            ? ($serviceName . ' (' . $p->assessment_type_name . ')')
            : $serviceName;

        return response()->json([
            'appointment' => [
                'id'        => $p->id,
                'reference' => $p->reference,
                'status'    => $this->stage($p),
                'service'   => [
                    'id'               => $p->service_id,
                    'name'             => $serviceName,
                    'full_name'        => $serviceFullName,
                    'assessment_type'  => $p->assessment_type_name,
                ],
                'psychologist' => $psych
                    ? ['name' => trim($psych->first_name . ' ' . $psych->last_name)]
                    : null, // ABA / Assessment: "To be assigned"
                'date'       => $p->start_at->format('Y-m-d'),
                'start_time' => $p->start_at->format('H:i'),
                'end_time'   => $p->end_at->format('H:i'),
                'client' => [
                    'name'   => $profile->full_name ?? trim($user->first_name . ' ' . $user->last_name),
                    'email'  => $profile->email ?? $user->email,
                    'mobile' => $profile->mobile ?? $user->phone,
                ],
                'payment' => [
                    'method'           => $p->method_name,
                    'reference_number' => $p->reference_number,
                    'amount_paid'      => (float) $p->amount_paid,
                    'balance'          => (float) $p->balance,
                    'total_fee'        => (float) $p->total_amount,
                    'receipt_url'      => $p->receipt_path
                        ? Storage::disk('public')->url($p->receipt_path)
                        : null,
                ],
                'consent_required' => in_array($p->service_id, ['psychotherapy', 'aba'], true),
                'consent_complete' => $this->consentDone($p),
            ],
        ]);
    }
}