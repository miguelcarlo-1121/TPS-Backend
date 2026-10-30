<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\ProviderAvailability;
use App\Models\SlotHold;
use App\Models\User;
use App\Models\BookingPayment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BookingAvailabilityController extends Controller
{
    private const DURATIONS = [
        'psychotherapy' => 60,
        'aba'           => 60,
        'assessment'    => 60,
    ];
    private const DEFAULT_DURATION = 60;
    private const HOLD_MINUTES = 60;

    private function duration(string $service): int
    {
        return self::DURATIONS[$service] ?? self::DEFAULT_DURATION;
    }

    private function providerIds(string $service, $psychologistId): array
    {
        if ($service === 'psychotherapy') {
            $users = User::whereIn('role', ['ADMIN', 'AFFILIATE'])->where('is_active', true);

            if ($psychologistId) {
                $users->where('id', $psychologistId);
            }

            return Provider::whereIn('user_id', $users->pluck('id'))->pluck('id')->all();
        }

        // ABA / Assessment: ADMIN lang
        $adminIds = User::where('role', 'ADMIN')->where('is_active', true)->pluck('id');

        return Provider::whereIn('user_id', $adminIds)->pluck('id')->all();
    }

    private function candidates(array $providerIds, Carbon $from, Carbon $to, int $dur, int $userId)
    {
        if (!$providerIds) return collect();

        $avail = ProviderAvailability::whereIn('provider_id', $providerIds)
            ->where('status', 'available')
            ->where('start_at', '<', $to)
            ->where('end_at', '>', $from)
            ->orderBy('start_at')
            ->get();

        $holds = SlotHold::whereIn('provider_id', $providerIds)
            ->where('expires_at', '>', now())
            ->where('user_id', '!=', $userId)
            ->where('start_at', '<', $to)
            ->where('end_at', '>', $from)
            ->get();

        $booked = BookingPayment::whereIn('provider_id', $providerIds)
    ->whereIn('status', ['to_verify', 'verified'])
     ->where('approval_status', '!=', 'declined') 
     ->whereNull('cancelled_at') 
    ->where('start_at', '<', $to)
    ->where('end_at', '>', $from)
    ->get();

        $now = now();
        $out = collect();

        foreach ($avail as $a) {
            $cursor = $a->start_at->copy();

            while ($cursor->copy()->addMinutes($dur)->lte($a->end_at)) {
                $end = $cursor->copy()->addMinutes($dur);

                $blocked = $holds->contains(
        fn ($h) => $h->provider_id === $a->provider_id
            && $h->start_at->lt($end)
            && $h->end_at->gt($cursor)
    )
    || $booked->contains(
        fn ($b) => (int) $b->provider_id === (int) $a->provider_id
            && $b->start_at->lt($end)
            && $b->end_at->gt($cursor)
    );

                if ($cursor->gt($now) && $cursor->gte($from) && $cursor->lt($to) && !$blocked) {
                    $out->push([
                        'provider_id' => $a->provider_id,
                        'start'       => $cursor->copy(),
                        'end'         => $end,
                    ]);
                }

                $cursor = $end;
            }
        }

        return $out;
    }

    // GET /api/booking/availability
    public function days(Request $request)
    {
        $data = $request->validate([
            'service'         => ['required', 'string', 'max:50'],
            'psychologist_id' => ['nullable', 'integer'],
            'month'           => ['required', 'date_format:Y-m'],
        ]);

        $ids   = $this->providerIds($data['service'], $data['psychologist_id'] ?? null);
        $start = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        $days = $this->candidates($ids, $start, $end, $this->duration($data['service']), $request->user()->id)
            ->map(fn ($c) => $c['start']->format('Y-m-d'))
            ->unique()
            ->values();

        return response()->json(['days' => $days]);
    }

    // GET /api/booking/slots
    public function slots(Request $request)
    {
        $data = $request->validate([
            'service'         => ['required', 'string', 'max:50'],
            'psychologist_id' => ['nullable', 'integer'],
            'date'            => ['required', 'date_format:Y-m-d'],
        ]);

        $ids   = $this->providerIds($data['service'], $data['psychologist_id'] ?? null);
        $start = Carbon::parse($data['date'])->startOfDay();
        $end   = $start->copy()->endOfDay();

        $slots = $this->candidates($ids, $start, $end, $this->duration($data['service']), $request->user()->id)
            ->sortBy(fn ($c) => $c['start']->timestamp)
            ->unique(fn ($c) => $c['start']->format('H:i'))
            ->map(fn ($c) => [
                'start_time' => $c['start']->format('H:i'),
                'end_time'   => $c['end']->format('H:i'),
            ])
            ->values();

        return response()->json(['slots' => $slots]);
    }

    // GET /api/booking/psychologists?date=2026-10-14&start_time=09:00
    // Schedule-first: psychologists na may bakante sa napiling date at oras
    public function psychologistsForSlot(Request $request)
    {
        $data = $request->validate([
            'date'       => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
        ]);

        $ids      = $this->providerIds('psychotherapy', null);
        $dayStart = Carbon::parse($data['date'])->startOfDay();
        $dayEnd   = $dayStart->copy()->endOfDay();

        $providerIds = $this->candidates(
            $ids, $dayStart, $dayEnd, $this->duration('psychotherapy'), $request->user()->id
        )
            ->filter(fn ($c) => $c['start']->format('H:i') === $data['start_time'])
            ->pluck('provider_id')
            ->unique();

        $userIds = Provider::whereIn('id', $providerIds)->pluck('user_id');

        $psychologists = User::whereIn('id', $userIds)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($u) => [
                'id'         => $u->id,
                'first_name' => $u->first_name,
                'last_name'  => $u->last_name,
                'name'       => trim($u->first_name . ' ' . $u->last_name),
                'role'       => $u->role,
                'avatar_url' => $u->avatar_url
                    ?? ($u->avatar_path ? Storage::disk('public')->url($u->avatar_path) : null),
            ])
            ->values();

        return response()->json(['psychologists' => $psychologists]);
    }

    // POST /api/booking/hold
    public function hold(Request $request)
    {
        $data = $request->validate([
            'service'         => ['required', 'string', 'max:50'],
            'psychologist_id' => ['nullable', 'integer'],
            'date'            => ['required', 'date_format:Y-m-d'],
            'start_time'      => ['required', 'date_format:H:i'],
        ]);

        // Psychotherapy: kailangan na ng psychologist bago mag-hold
        if ($data['service'] === 'psychotherapy' && empty($data['psychologist_id'])) {
            return response()->json(['message' => 'Please choose a psychologist first.'], 422);
        }

        $user = $request->user();
        $dur  = $this->duration($data['service']);
        $ids  = $this->providerIds($data['service'], $data['psychologist_id'] ?? null);

        if (!$ids) {
            return response()->json(['message' => 'No schedule found for this selection.'], 422);
        }

        $dayStart = Carbon::parse($data['date'])->startOfDay();
        $dayEnd   = $dayStart->copy()->endOfDay();

        return DB::transaction(function () use ($data, $user, $dur, $ids, $dayStart, $dayEnd) {
            Provider::whereIn('id', $ids)->lockForUpdate()->get();

            $match = $this->candidates($ids, $dayStart, $dayEnd, $dur, $user->id)
                ->first(fn ($c) => $c['start']->format('H:i') === $data['start_time']);

            if (!$match) {
                return response()->json([
                    'message' => 'That time was just taken or is no longer available. Please pick another.',
                ], 409);
            }

            SlotHold::where('user_id', $user->id)->delete();

            $hold = SlotHold::create([
                'provider_id' => $match['provider_id'],
                'user_id'     => $user->id,
                'service_id'  => $data['service'],
                'start_at'    => $match['start'],
                'end_at'      => $match['end'],
                'expires_at'  => now()->addMinutes(self::HOLD_MINUTES),
            ]);

            return response()->json([
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

    // DELETE /api/booking/hold/{id}
    public function release(Request $request, $id)
    {
        SlotHold::where('id', $id)->where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Released']);
    }
}