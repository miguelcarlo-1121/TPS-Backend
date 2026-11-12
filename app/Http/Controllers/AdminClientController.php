<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ClientProfile;
use App\Models\BookingPayment;
use App\Models\ConsentForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminClientController extends Controller
{
    /**
     * GET /api/admin/clients
     */
    public function index(Request $request)
    {
        $query = User::where('role', 'CLIENT');

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($w) use ($q) {
                $w->where('first_name', 'like', "%{$q}%")
                  ->orWhere('last_name', 'like', "%{$q}%")
                  ->orWhere('email', 'like', "%{$q}%")
                  ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        $clients = $query->orderByDesc('id')->get();
        $clientIds = $clients->pluck('id');

        $profiles = ClientProfile::whereIn('user_id', $clientIds)
            ->orderByDesc('id')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');
        $payments = BookingPayment::whereIn('user_id', $clientIds)->get()->groupBy('user_id');

        $result = $clients->map(function (User $u) use ($profiles, $payments) {
            $prof = $profiles->get($u->id);
            $userPayments = $payments->get($u->id, collect());

            return [
                'id'             => $u->id,
                'name'           => trim($u->first_name . ' ' . $u->last_name),
                'first_name'     => $u->first_name,
                'last_name'      => $u->last_name,
                'email'          => $u->email,
                'phone'          => $u->phone ?: ($prof?->mobile ?? '—'),
                'birthdate'      => $prof?->birthdate ? $prof->birthdate->format('Y-m-d') : '',
                'address'        => '—',
                'guardian'       => '',
                'emergencyName'  => $prof?->emergency_name ?? '',
                'emergencyPhone' => $prof?->emergency_phone ?? '',
                'emergencyRelation' => $prof?->emergency_relation ?? '',
                'status'         => $u->is_active ? 'Active' : 'Inactive',
                'joined'         => $u->created_at ? $u->created_at->format('Y-m-d') : '—',
                'appointments_count' => $userPayments->count(),
                'payments_count'     => $userPayments->where('status', 'verified')->count(),
            ];
        });

        return response()->json(['clients' => $result]);
    }

    /**
     * GET /api/admin/clients/{id}
     */
    public function show($id)
    {
        $user = User::where('role', 'CLIENT')->findOrFail($id);
        $profile = ClientProfile::where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        $payments = BookingPayment::where('user_id', $user->id)
            ->orderByDesc('start_at')
            ->orderByDesc('id')
            ->get();

        $serviceNames = DB::table('services')
            ->whereIn('slug', $payments->pluck('service_id')->unique())
            ->pluck('name', 'slug');
        $psychIds = $payments->pluck('psychologist_user_id')->filter()->unique();
        $psychs = User::whereIn('id', $psychIds)->get()->keyBy('id');

        $appointments = $payments->map(function ($p) use ($psychs, $serviceNames) {
            $psych = $p->psychologist_user_id ? $psychs->get($p->psychologist_user_id) : null;
            $psychName = $psych ? trim($psych->first_name . ' ' . $psych->last_name) : 'Clinic Assigned';
            $serviceName = $serviceNames[$p->service_id] ?? match ($p->service_id) {
                'psychotherapy' => 'Psychotherapy',
                'aba' => 'ABA Therapy',
                'assessment' => 'Assessment',
                default => ucfirst(str_replace('-', ' ', $p->service_id)),
            };
            $status = $p->status === 'rejected'
                ? 'Rejected'
                : ($p->status === 'to_verify'
                    ? 'Payment pending'
                    : match ($p->approval_status) {
                        'approved' => 'Approved',
                        'declined' => 'Declined',
                        default => 'Approval pending',
                    });

            return [
                'id'        => $p->id,
                'reference' => $p->reference,
                'date'      => $p->start_at->format('Y-m-d · g:i A'),
                'service'   => $p->assessment_type_name
                    ? "{$serviceName} ({$p->assessment_type_name})"
                    : $serviceName,
                'by'        => $psychName,
                'mode'      => 'Online / In-person',
                'status'    => $status,
            ];
        });

        $paymentRecords = $payments->map(function ($p) {
            return [
                'id'      => $p->id,
                'date'    => $p->start_at->format('Y-m-d'),
                'amount'  => (float) $p->amount_paid,
                'method'  => $p->method_name,
                'status'  => $p->status === 'verified'
                    ? 'Paid'
                    : ($p->status === 'rejected' ? 'Rejected' : 'Pending'),
            ];
        });

        return response()->json([
            'client' => [
                'id'             => $user->id,
                'name'           => trim($user->first_name . ' ' . $user->last_name),
                'first_name'     => $user->first_name,
                'last_name'      => $user->last_name,
                'email'          => $user->email,
                'phone'          => $user->phone ?: ($profile?->mobile ?? ''),
                'birthdate'      => $profile?->birthdate ? $profile->birthdate->format('Y-m-d') : '',
                'address'        => '—',
                'guardian'       => '',
                'emergencyName'  => $profile?->emergency_name ?? '',
                'emergencyPhone' => $profile?->emergency_phone ?? '',
                'emergencyRelation' => $profile?->emergency_relation ?? '',
                'status'         => $user->is_active ? 'Active' : 'Inactive',
                'joined'         => $user->created_at ? $user->created_at->format('Y-m-d') : '—',
                'appointments'   => $appointments,
                'payments'       => $paymentRecords,
            ],
        ]);
    }

    /**
     * PUT /api/admin/clients/{id}
     */
    public function update(Request $request, $id)
    {
        $user = User::where('role', 'CLIENT')->findOrFail($id);

        $data = $request->validate([
            'name'           => 'required|string|min:2|max:150',
            'email'          => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'          => 'nullable|string|regex:/^09\d{9}$/',
            'birthdate'      => 'nullable|date',
            'emergencyName'  => 'nullable|string|max:150',
            'emergencyPhone' => 'nullable|string|regex:/^09\d{9}$/',
            'status'         => 'nullable|in:Active,Inactive',
        ], [
            'phone.regex'          => 'Phone number must be 11 digits starting with 09 (e.g. 09123456789).',
            'emergencyPhone.regex' => 'Emergency phone must be 11 digits starting with 09 (e.g. 09123456789).',
        ]);

        $parts = explode(' ', trim($data['name']), 2);
        $user->first_name = $parts[0];
        $user->last_name  = $parts[1] ?? '';
        $user->email      = strtolower(trim($data['email']));
        if (!empty($data['phone'])) {
            $user->phone = trim($data['phone']);
        }
        if (isset($data['status'])) {
            $user->is_active = ($data['status'] === 'Active');
        }
        $user->save();

        if (!empty($data['birthdate']) || !empty($data['emergencyName']) || !empty($data['emergencyPhone'])) {
            $profile = ClientProfile::where('user_id', $user->id)
                ->orderByDesc('id')
                ->first();
            if ($profile) {
                $profile->fill([
                    'full_name'          => trim($data['name']),
                    'birthdate'          => $data['birthdate'] ?? '2000-01-01',
                    'email'              => $user->email,
                    'mobile'             => $user->phone ?? '09000000000',
                    'emergency_name'     => $data['emergencyName'] ?? '',
                    'emergency_relation' => 'Emergency Contact',
                    'emergency_phone'    => $data['emergencyPhone'] ?? '09000000000',
                ])->save();
            }
        }

        return response()->json([
            'message' => 'Client information updated successfully.',
            'client'  => [
                'id'    => $user->id,
                'name'  => trim($user->first_name . ' ' . $user->last_name),
                'email' => $user->email,
            ],
        ]);
    }
}
