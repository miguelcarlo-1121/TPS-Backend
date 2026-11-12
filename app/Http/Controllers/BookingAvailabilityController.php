<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\ProviderAvailability;
use App\Models\SlotHold;
use App\Models\User;
use App\Models\BookingPayment;
use App\Services\SchedulingEngineService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BookingAvailabilityController extends Controller
{
    private const DURATIONS = [
        'psychotherapy' => 60,
        'aba'           => 120,
        'assessment'    => 60,
    ];
    private const DEFAULT_DURATION = 60;
    private const HOLD_MINUTES = 60;
    private const SLOT_INTERVAL_MINUTES = 30;

    private function resolveService(string $service): ?object
    {
        $record = DB::table('services')->where('slug', $service)->first();
        if ($record) {
            return $record;
        }

        $legacyNames = [
            'psychotherapy' => fn (string $name) => str_contains($name, 'psychotherapy'),
            'aba' => fn (string $name) => $name === 'abatherapy',
            'assessment' => fn (string $name) => in_array($name, ['assessmentandconsultation', 'assessmentconsultation'], true),
        ];
        $matchesLegacyName = $legacyNames[$service] ?? null;
        if (!$matchesLegacyName) {
            return null;
        }

        return DB::table('services')
            ->get()
            ->first(fn ($row) => $matchesLegacyName(strtolower(preg_replace(
                '/[^a-z0-9]/i',
                '',
                str_replace('&', 'and', (string) $row->name)
            ))));
    }

    private function isPsychotherapy(string $service): bool
    {
        $record = $this->resolveService($service);
        $name = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($record->name ?? '')));

        return $service === 'psychotherapy' || str_contains($name, 'psychotherapy');
    }

    private function isAssessment(string $service): bool
    {
        $record = $this->resolveService($service);
        $name = strtolower(preg_replace(
            '/[^a-z0-9]/i',
            '',
            str_replace('&', 'and', (string) ($record->name ?? ''))
        ));

        return $service === 'assessment'
            || in_array($name, ['assessmentandconsultation', 'assessmentconsultation'], true);
    }

    private function duration(string $service, ?int $assessmentTypeId = null): int
    {
        if ($this->isAssessment($service) && $assessmentTypeId) {
            $type = DB::table('assessment_types')->find($assessmentTypeId);
            if ($type && $type->duration_minutes > 0) {
                return (int) $type->duration_minutes;
            }
        }

        $s = DB::table('services')->where('slug', $service)->first();
        if ($s && $s->duration_minutes > 0) {
            return (int) $s->duration_minutes;
        }

        return self::DURATIONS[$service] ?? self::DEFAULT_DURATION;
    }

    private function providerIds(string $service, $psychologistId): array
    {
        if ($this->isPsychotherapy($service)) {
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
            // Laging 30-minute boundary ang start (9:00, 9:30, ...) kahit hindi aligned ang availability
            $cursor = $a->start_at->copy()->startOfMinute();
            if ($cursor->lt($a->start_at)) {
                $cursor->addMinute();
            }
            $mod = $cursor->minute % self::SLOT_INTERVAL_MINUTES;
            if ($mod !== 0) {
                $cursor->addMinutes(self::SLOT_INTERVAL_MINUTES - $mod);
            }

            while ($cursor->copy()->addMinutes($dur)->lte($a->end_at)) {
                $end = $cursor->copy()->addMinutes($dur);

               $blocked = $holds->contains(
        fn ($h) => (int) $h->provider_id === (int) $a->provider_id
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

                $cursor->addMinutes(self::SLOT_INTERVAL_MINUTES);
            }
        }

        return $out;
    }

    // GET /api/booking/availability
    public function days(Request $request)
    {
        $data = $request->validate([
            'service'            => ['required', 'string', 'max:50'],
            'psychologist_id'    => ['nullable', 'integer'],
            'provider_id'        => ['nullable', 'integer'],
            'assessment_type_id' => ['nullable', 'integer'],
            'month'              => ['required', 'date_format:Y-m'],
        ]);
        $service = $this->resolveService($data['service']);
        if (!$service || !$service->enabled) {
            return response()->json(['message' => 'This service is not currently available.'], 404);
        }
        $data['service'] = $service->slug;

        $ids = $this->providerIds($data['service'], $data['psychologist_id'] ?? null);

        if (!empty($data['provider_id'])) {
            $ids = array_values(array_intersect($ids, [(int) $data['provider_id']]));
        }

        $start = Carbon::createFromFormat('!Y-m', $data['month'])->startOfMonth();
        $end   = $start->copy()->endOfMonth();
        $dur   = $this->duration($data['service'], $data['assessment_type_id'] ?? null);

        $days = $this->candidates($ids, $start, $end, $dur, $request->user()->id)
            ->map(fn ($c) => $c['start']->format('Y-m-d'))
            ->unique()
            ->values();

        return response()->json(['days' => $days]);
    }

    // GET /api/booking/slots
    public function slots(Request $request)
    {
        $data = $request->validate([
            'service'            => ['required', 'string', 'max:50'],
            'psychologist_id'    => ['nullable', 'integer'],
            'provider_id'        => ['nullable', 'integer'],
            'assessment_type_id' => ['nullable', 'integer'],
            'date'               => ['required', 'date_format:Y-m-d'],
        ]);
        $service = $this->resolveService($data['service']);
        if (!$service || !$service->enabled) {
            return response()->json(['message' => 'This service is not currently available.'], 404);
        }
        $data['service'] = $service->slug;

        $ids = $this->providerIds($data['service'], $data['psychologist_id'] ?? null);

        if (!empty($data['provider_id'])) {
            $ids = array_values(array_intersect($ids, [(int) $data['provider_id']]));
        }

        $start = Carbon::parse($data['date'])->startOfDay();
        $end   = $start->copy()->endOfDay();
        $dur   = $this->duration($data['service'], $data['assessment_type_id'] ?? null);

        $slots = $this->candidates($ids, $start, $end, $dur, $request->user()->id)
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
            'service'            => ['required', 'string', 'max:50'],
            'psychologist_id'    => ['nullable', 'integer'],
            'provider_id'        => ['nullable', 'integer'],
            'assessment_type_id' => ['nullable', 'integer'],
            'date'               => ['required', 'date_format:Y-m-d'],
            'start_time'         => ['required', 'date_format:H:i'],
        ]);
        $service = $this->resolveService($data['service']);
        if (!$service || !$service->enabled) {
            return response()->json(['message' => 'This service is not currently available.'], 404);
        }
        $data['service'] = $service->slug;

        // Psychotherapy: kailangan na ng psychologist bago mag-hold
        $isPsychotherapy = $this->isPsychotherapy($data['service']);
        if ($isPsychotherapy && empty($data['psychologist_id'])) {
            return response()->json(['message' => 'Please choose a psychologist first.'], 422);
        }

        $assessmentType = null;
        if ($this->isAssessment($data['service']) && !empty($data['assessment_type_id'])) {
            $assessmentType = DB::table('assessment_types')->find($data['assessment_type_id']);
        }

        $user = $request->user();
        $dur  = $this->duration($data['service'], $data['assessment_type_id'] ?? null);
        $ids  = $this->providerIds($data['service'], $data['psychologist_id'] ?? null);

        if (!empty($data['provider_id'])) {
            $ids = array_values(array_intersect($ids, [(int) $data['provider_id']]));
        }

        if (!$ids) {
            return response()->json(['message' => 'No schedule found for this selection.'], 422);
        }

        $dayStart = Carbon::parse($data['date'])->startOfDay();
        $dayEnd   = $dayStart->copy()->endOfDay();

        return DB::transaction(function () use ($data, $user, $dur, $ids, $dayStart, $dayEnd, $assessmentType, $isPsychotherapy) {
            Provider::whereIn('id', $ids)->lockForUpdate()->get();

            $match = $this->candidates($ids, $dayStart, $dayEnd, $dur, $user->id)
                ->first(fn ($c) => $c['start']->format('H:i') === $data['start_time']);

            if (!$match) {
                return response()->json([
                    'message' => 'That time was just taken or is no longer available. Please pick another.',
                ], 409);
            }

            // --- Scheduling-engine double-check (async advisory) ---
            $matchProvider = Provider::find($match['provider_id']);
            $engineResult  = app(SchedulingEngineService::class)->solve(
                serviceSlug: $data['service'],
                date:        $data['date'],
                startTime:   $data['start_time'],
                endTime:     $match['end']->format('H:i'),
                providerId:  $match['provider_id'],
                userId:      $user->id,
            );

            if ($engineResult !== null && SchedulingEngineService::isNotFeasible($engineResult)) {
                // Engine explicitly flagged a conflict — surface recommendations to the client.
                // Keep only slots that are still free in Laravel (engine does not know other clients' holds).
                $recs = $this->bookableRecommendations(
                    SchedulingEngineService::allRecommendations($engineResult),
                    $ids,
                    $dur,
                    $user->id,
                    $isPsychotherapy,
                );
                Log::info('BookingAvailabilityController@hold: engine flagged NOT_FEASIBLE', [
                    'user_id'    => $user->id,
                    'service'    => $data['service'],
                    'date'       => $data['date'],
                    'start_time' => $data['start_time'],
                    'recs_count' => count($recs),
                ]);
                return response()->json([
                    'message'         => 'That time was just taken or is no longer available. Please pick another.',
                    'recommendations' => $recs,
                ], 409);
            }
            // If engine is unreachable ($engineResult === null) or returns FEASIBLE, proceed normally.
            // -------------------------------------------------------

            // --- ABA / Assessment: ang Admin na pinili ng ILP ang gagamitin (isa lang) ---
            $holdProviderId = $match['provider_id'];

            if (!$isPsychotherapy && SchedulingEngineService::isFeasible($engineResult)) {
                $assigned = SchedulingEngineService::assignedAdminProviderId($engineResult);

                if ($assigned !== null && $assigned !== (int) $holdProviderId
                    && in_array($assigned, array_map('intval', $ids), true)) {
                    // Hindi alam ng engine ang holds ng ibang client, kaya tiyaking libre pa talaga
                    // ang napiling Admin sa oras na ito bago gamitin.
                    $free = $this->candidates([$assigned], $dayStart, $dayEnd, $dur, $user->id)
                        ->contains(fn ($c) => $c['start']->format('H:i') === $data['start_time']);

                    if ($free) {
                        $holdProviderId = $assigned;
                    } else {
                        Log::warning('BookingAvailabilityController@hold: ILP admin has a conflicting hold, using first free admin', [
                            'ilp_admin' => $assigned,
                            'fallback'  => $holdProviderId,
                        ]);
                    }
                }

                if (SchedulingEngineService::reassignments($engineResult)) {
                    Log::warning('BookingAvailabilityController@hold: ILP proposed reassignments (not applied)', [
                        'reassignments' => SchedulingEngineService::reassignments($engineResult),
                    ]);
                }
            }

            SlotHold::where('user_id', $user->id)->delete();

            $hold = SlotHold::create([
                'provider_id'          => $holdProviderId,
                'user_id'              => $user->id,
                'service_id'           => $data['service'],
                'assessment_type_id'   => $assessmentType ? $assessmentType->id : null,
                'assessment_type_name' => $assessmentType ? $assessmentType->name : null,
                'start_at'             => $match['start'],
                'end_at'               => $match['end'],
                'expires_at'           => now()->addMinutes(self::HOLD_MINUTES),
            ]);

            return response()->json([
                'hold' => [
                    'id'                   => $hold->id,
                    'provider_id'          => $hold->provider_id,
                    'assessment_type_id'   => $hold->assessment_type_id,
                    'assessment_type_name' => $hold->assessment_type_name,
                    'date'                 => $hold->start_at->format('Y-m-d'),
                    'start_time'           => $hold->start_at->format('H:i'),
                    'end_time'             => $hold->end_at->format('H:i'),
                    'expires_at'           => $hold->expires_at->toIso8601String(),
                ],
                'engine_feasible' => SchedulingEngineService::isFeasible($engineResult),
            ], 201);
        });
    }

    // GET /api/booking/recommendations?service=..&date=YYYY-MM-DD[&start_time=HH:MM][&psychologist_id=..][&assessment_type_id=..]
    // History-based na mungkahing oras (Stage 2 ng engine).
    //  - may start_time: ang oras na iyon ang sinusuri; kung hindi feasible, ibabalik ang alternatives.
    //  - walang start_time: ang "usual" na oras ng client (galing sa huling completed session) ang sinusuri.
    public function recommendations(Request $request)
    {
        $data = $request->validate([
            'service'            => ['required', 'string', 'max:50'],
            'psychologist_id'    => ['nullable', 'integer'],
            'provider_id'        => ['nullable', 'integer'],
            'assessment_type_id' => ['nullable', 'integer'],
            'date'               => ['required', 'date_format:Y-m-d'],
            'start_time'         => ['nullable', 'date_format:H:i'],
        ]);
        $service = $this->resolveService($data['service']);
        if (!$service || !$service->enabled) {
            return response()->json(['recommendations' => [], 'reason' => 'SERVICE_UNAVAILABLE'], 404);
        }
        $data['service'] = $service->slug;

        $none = fn (?string $reason = null) => response()->json(['recommendations' => [], 'reason' => $reason]);

        $user   = $request->user();
        $engine = app(SchedulingEngineService::class);

        $history = $engine->buildHistory($data['service'], $user->id);
        if (!$history) {
            return $none('NO_HISTORY');
        }

        $ids = $this->providerIds($data['service'], $data['psychologist_id'] ?? null);
        if (!empty($data['provider_id'])) {
            $ids = array_values(array_intersect($ids, [(int) $data['provider_id']]));
        }
        if (!$ids) {
            return $none('NO_PROVIDER');
        }

        $isPsycho   = $this->isPsychotherapy($data['service']);
        $providerId = $isPsycho ? (int) $ids[0] : null;
        if ($isPsycho && empty($data['psychologist_id'])) {
            return $none('NO_PSYCHOLOGIST');
        }

        $startTime = $data['start_time'] ?? $history['start'];
        $dur       = $this->duration($data['service'], $data['assessment_type_id'] ?? null);
        $start     = Carbon::parse($data['date'] . ' ' . $startTime);
        $end       = $start->copy()->addMinutes($dur);

        if ($start->lte(now()) || $start->minute % self::SLOT_INTERVAL_MINUTES !== 0) {
            return $none('UNAVAILABLE_TIME');
        }

        $result = $engine->solve(
            serviceSlug: $data['service'],
            date:        $data['date'],
            startTime:   $start->format('H:i'),
            endTime:     $end->format('H:i'),
            providerId:  $providerId,
            userId:      $user->id,
        );

        if ($result === null) {
            return $none('ENGINE_UNAVAILABLE');
        }

        if (SchedulingEngineService::isNotFeasible($result)) {
            $recs = SchedulingEngineService::allRecommendations($result);
        } elseif (SchedulingEngineService::isFeasible($result)) {
            // Bakante ang "usual" na oras sa petsang ito -> ito mismo ang mungkahi.
            $recs = [[
                'rank'        => 1,
                'tier'        => ($start->dayOfWeekIso === $history['weekday'] && $startTime === $history['start'])
                    ? 'HISTORY_MATCH' : 'HISTORY_NEAR',
                'provider_id' => null,
                'date'        => $start->format('Y-m-d'),
                'start'       => $start->format('H:i'),
                'end'         => $end->format('H:i'),
                'status'      => 'RECOMMENDATION_FOUND',
            ]];
            // Kung ang oras na ito ay hindi mismo ang usual ng client (may start_time na ipinasa), walang mungkahi.
            if (!empty($data['start_time']) && $startTime !== $history['start']) {
                $recs = [];
            }
        } else {
            // Hindi COMPLETED: gamitin ang tunay na dahilan (invalid code / job_status).
            // Huwag gamitin ang `reason` ng engine dito: maaaring "NO_HISTORY" iyon kahit hindi iyon ang totoong dahilan.
            return $none($result['invalid'][0]['code'] ?? ($result['job_status'] ?? 'ENGINE_ERROR'));
        }

        $recs = $this->bookableRecommendations($recs, $ids, $dur, $user->id, $isPsycho);

        return response()->json(['recommendations' => $recs, 'reason' => $recs ? null : 'NO_ALTERNATIVE']);
    }

    /**
     * Keep engine recommendations that are still free in Laravel, and hide Admin IDs for ABA/Assessment.
     */
    private function bookableRecommendations(array $recs, array $ids, int $dur, int $userId, bool $isPsycho): array
    {
        $recs = array_values(array_filter($recs, function ($r) use ($ids, $dur, $userId) {
            if (empty($r['date']) || empty($r['start'])) {
                return false;
            }
            $ds = Carbon::parse($r['date'])->startOfDay();
            return $this->candidates($ids, $ds, $ds->copy()->endOfDay(), $dur, $userId)
                ->contains(fn ($c) => $c['start']->format('H:i') === $r['start']);
        }));

        return array_map(function ($r) use ($isPsycho) {
            if (!$isPsycho) {
                $r['provider_id'] = null;
            }
            return $r;
        }, $recs);
    }

    // DELETE /api/booking/hold/{id}
    public function release(Request $request, $id)
    {
        SlotHold::where('id', $id)->where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Released']);
    }
}