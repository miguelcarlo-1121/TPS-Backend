<?php

namespace App\Http\Controllers;

use App\Models\ClientProfile;
use App\Models\BookingPayment;
use App\Models\SlotHold;
use Illuminate\Http\Request;

class ClientInfoController extends Controller
{
    private function format(ClientProfile $p): array
    {
        return [
            'full_name'          => $p->full_name,
            'birthdate'          => $p->birthdate->format('Y-m-d'),
            'email'              => $p->email,
            'mobile'             => $p->mobile,
            'emergency_name'     => $p->emergency_name,
            'emergency_relation' => $p->emergency_relation,
            'emergency_phone'    => $p->emergency_phone,
        ];
    }

    // GET /api/booking/client-info
    // Kung may naka-save na, ibalik. Kung wala, i-prefill mula sa account.
    public function show(Request $request)
    {
        $user    = $request->user();
        $profile = ClientProfile::where('user_id', $user->id)->orderByDesc('id')->first();
        $accountName = trim($user->first_name . ' ' . $user->last_name);

        if ($profile) {
            return response()->json([
                'info' => array_merge($this->format($profile), ['full_name' => $accountName]),
                'saved' => true,
            ]);
        }

        $email = ($user->email && !str_ends_with($user->email, '@facebook.local')) ? $user->email : '';
        $phone = preg_match('/^09\d{9}$/', (string) $user->phone) ? $user->phone : '';

        return response()->json([
            'saved' => false,
            'info'  => [
                'full_name'          => $accountName,
                'birthdate'          => '',
                'email'              => $email,
                'mobile'             => $phone,
                'emergency_name'     => '',
                'emergency_relation' => '',
                'emergency_phone'    => '',
            ],
        ]);
    }

    // POST /api/booking/client-info
    public function store(Request $request)
    {
        $user = $request->user();
        $holdData = $request->validate([
            'hold_id' => ['required', 'integer'],
        ]);
        $hold = SlotHold::where('id', $holdData['hold_id'])
            ->where('user_id', $user->id)
            ->where('expires_at', '>', now())
            ->first();

        if (!$hold) {
            return response()->json([
                'message' => 'Your held time has expired. Please pick a date and time again.',
            ], 409);
        }

        $nameRule = ['required', 'string', 'min:2', 'max:150', 'regex:/^[\pL][\pL\s.\'’-]*$/u'];
        $phoneRule = ['required', 'regex:/^09\d{9}$/'];

        $profileData = $request->validate([
            'full_name'          => $nameRule,
            'birthdate'          => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1899-12-31'],
            'email'              => ['required', 'email:rfc', 'max:190'],
            'mobile'             => $phoneRule,
            'emergency_name'     => $nameRule,
            'emergency_relation' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL][\pL\s.\'’-]*$/u'],
            'emergency_phone'    => $phoneRule,
        ], [
            'full_name.regex'          => 'Full name must not contain numbers.',
            'emergency_name.regex'     => 'Contact name must not contain numbers.',
            'emergency_relation.regex' => 'Relationship must contain letters only.',
            'birthdate.before_or_equal' => 'Date of birth cannot be in the future.',
            'mobile.regex'             => 'Mobile number must be 11 digits (e.g. 09123456789).',
            'emergency_phone.regex'    => 'Contact phone must be 11 digits (e.g. 09123456789).',
        ]);

        $profileData['full_name']          = trim($user->first_name . ' ' . $user->last_name);
        $profileData['emergency_name']     = trim($profileData['emergency_name']);
        $profileData['emergency_relation'] = trim($profileData['emergency_relation']);
        $profileData['email']              = strtolower(trim($profileData['email']));

        $rejectedBooking = BookingPayment::where('user_id', $user->id)
            ->where('status', 'rejected')
            ->where('provider_id', $hold->provider_id)
            ->where('service_id', $hold->service_id)
            ->where('start_at', $hold->start_at)
            ->where('end_at', $hold->end_at)
            ->latest('id')
            ->first();
        $profile = $rejectedBooking
            ? ClientProfile::where('booking_payment_id', $rejectedBooking->id)->first()
            : null;

        if ($profile) {
            $profile->fill($profileData + ['slot_hold_id' => $hold->id])->save();
        } else {
            $profile = ClientProfile::updateOrCreate(
                ['user_id' => $user->id, 'slot_hold_id' => $hold->id],
                $profileData
            );
        }

        return response()->json(['info' => $this->format($profile)]);
    }
}