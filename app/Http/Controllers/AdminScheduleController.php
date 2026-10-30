<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\ProviderAvailability;
use Carbon\Carbon;
use App\Models\BookingPayment;
use Illuminate\Http\Request;

class AdminScheduleController extends Controller
{
    protected string $role = 'admin';
    protected function providerId(Request $request): int
{
    $user = $request->user();

   
    $provider = Provider::firstOrCreate(
        ['user_id' => $user->id],
        [
            'code'         => 'P' . str_pad((string) Provider::count(), 3, '0', STR_PAD_LEFT),
            'display_name' => trim($user->first_name . ' ' . $user->last_name),
        ]
    );

    return $provider->id;
}

    
    protected function format(ProviderAvailability $s): array
{
    
    $base = BookingPayment::where('provider_id', $s->provider_id)
        ->where('status', '!=', 'rejected')
        ->whereNull('cancelled_at')
        ->where('start_at', '>=', $s->start_at)
        ->where('end_at', '<=', $s->end_at);

    return [
        'id'         => $s->id,
        'date'       => $s->start_at->format('Y-m-d'),
        'start_time' => $s->start_at->format('H:i'),
        'end_time'   => $s->end_at->format('H:i'),
        'status'     => $s->status,
        'created_by' => $s->created_by_role,
        'booked'     => (clone $base)->where('approval_status', 'approved')->count(),
        'requested'  => (clone $base)->where('approval_status', 'pending')->count(),
    ];
}

    // GET /api/admin/schedule?month=2026-10
    public function index(Request $request)
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        $providerId = $this->providerId($request);

        $start = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        $slots = ProviderAvailability::where('provider_id', $providerId)
            ->whereBetween('start_at', [$start, $end])
            ->orderBy('start_at')
            ->get()
            ->map(fn ($s) => $this->format($s));

        $from = Carbon::today();
        $to   = Carbon::today()->addDays(30)->endOfDay();

            $stats = [
        'open_slots' => ProviderAvailability::where('provider_id', $providerId)
            ->where('status', 'available')
            ->whereBetween('start_at', [$from, $to])->count(),
        'booked' => BookingPayment::where('provider_id', $providerId)
            ->where('status', '!=', 'rejected')
            ->where('approval_status', 'approved')
            ->whereBetween('start_at', [$from, $to])->count(),
        'pending' => BookingPayment::where('provider_id', $providerId)
            ->where('status', '!=', 'rejected')
            ->where('approval_status', 'pending')
            ->whereBetween('start_at', [$from, $to])->count(),
        'not_available' => ProviderAvailability::where('provider_id', $providerId)
            ->where('status', 'unavailable')
            ->whereBetween('start_at', [$from, $to])->count(),
    ];

        return response()->json(['slots' => $slots, 'stats' => $stats]);
    }

    // POST /api/admin/schedule/slots
    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'status'     => ['required', 'in:available,not_available'],
            'start_time' => ['nullable', 'required_if:status,available', 'date_format:H:i'],
            'end_time'   => ['nullable', 'required_if:status,available', 'date_format:H:i', 'after:start_time'],
        ]);

        $providerId = $this->providerId($request);
        $dayStart = Carbon::parse($data['date'])->startOfDay();
        $dayEnd   = Carbon::parse($data['date'])->endOfDay();

        $base = ProviderAvailability::where('provider_id', $providerId)
            ->whereBetween('start_at', [$dayStart, $dayEnd]);

        // ---- Not available: sarado ang buong araw ----
        if ($data['status'] === 'not_available') {
            (clone $base)->delete();

            $slot = ProviderAvailability::create([
                'provider_id' => $providerId,
                'start_at'    => $data['date'] . ' 00:00:00',
                'end_at'      => $data['date'] . ' 23:59:00',
                'status'      => 'unavailable',
                'created_by_role' => $this->role,
            ]);

            return response()->json($this->format($slot), 201);
        }

        // ---- Available: pwedeng maraming slots sa isang araw ----
        if ((clone $base)->where('status', 'unavailable')->exists()) {
            return response()->json([
                'message' => 'This day is marked not available. Remove that first to add times.',
            ], 422);
        }

        $newStart = $data['date'] . ' ' . $data['start_time'] . ':00';
        $newEnd   = $data['date'] . ' ' . $data['end_time'] . ':00';

        // Bawal mag-overlap sa existing slot
        $overlap = (clone $base)
            ->where('status', 'available')
            ->where('start_at', '<', $newEnd)
            ->where('end_at', '>', $newStart)
            ->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'This time overlaps with a slot you already saved for this day.',
            ], 422);
        }

        $slot = ProviderAvailability::create([
            'provider_id' => $providerId,
            'start_at'    => $newStart,
            'end_at'      => $newEnd,
            'status'      => 'available',
            'created_by_role' => $this->role,
        ]);

        return response()->json($this->format($slot), 201);
    }

    // DELETE /api/admin/schedule/slots/{id}
    public function destroy(Request $request, $id)
{
    $slot = ProviderAvailability::where('provider_id', $this->providerId($request))
        ->findOrFail($id);

    if ($slot->start_at->lt(Carbon::today())) {
        return response()->json(['message' => 'Past dates cannot be changed.'], 422);
    }

    $slot->delete();

    return response()->json(['message' => 'Deleted']);
}
}