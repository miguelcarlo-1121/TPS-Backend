<?php

namespace App\Services;

use App\Models\BookingPayment;
use App\Models\Provider;
use App\Models\ProviderAvailability;
use App\Models\SlotHold;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Connects Laravel to the Python scheduling-engine FastAPI service.
 *
 * The engine exposes one endpoint:
 *   POST {SCHEDULING_ENGINE_URL}/api/v1/algorithm/solve
 *
 * This service:
 *   1. Builds the Contract A (SolveRequest) payload from live DB data.
 *   2. POSTs to the engine with the shared SERVICE_TOKEN.
 *   3. Returns the parsed Contract B (SolveResponse) as a PHP array,
 *      or null when the HTTP call itself fails (engine down / timeout).
 *
 * Callers decide what to do with FEASIBLE / NOT_FEASIBLE decisions.
 *
 * Service mapping (Laravel slug → engine service code):
 *   psychotherapy → PC
 *   aba           → ABA
 *   assessment    → AC
 */
class SchedulingEngineService
{
    // ---------------------------------------------------------------- constants

    private const SERVICE_MAP = [
        'psychotherapy' => 'PC',
        'aba'           => 'ABA',
        'assessment'    => 'AC',
    ];

    private const BOOKING_STATUS_MAP = [
        'to_verify' => 'PAID',
        'verified'  => 'CONFIRMED',
    ];

    // Horizon window sent to the engine (days either side of the candidate date)
    private const HORIZON_DAYS = 7;

    // ---------------------------------------------------------------- public API

    /**
     * Check whether a candidate slot is feasible and get alternative
     * recommendations if it is not.
     *
     * @param  string       $serviceSlug   'psychotherapy' | 'aba' | 'assessment'
     * @param  string       $date          'YYYY-MM-DD'  (the candidate date)
     * @param  string       $startTime     'HH:MM'
     * @param  string       $endTime       'HH:MM'
     * @param  int|null     $providerId    Provider id for psychotherapy (pinned),
     *                                     or null for ABA/Assessment.
     * @param  int|null     $excludeBookingId  BookingPayment id to exclude from
     *                                         active_bookings (used in reschedule/retry).
     * @return array|null   Decoded SolveResponse body, or null on engine failure.
     */
    public function solve(
        string $serviceSlug,
        string $date,
        string $startTime,
        string $endTime,
        ?int   $providerId       = null,
        ?int   $excludeBookingId = null,
        ?int   $userId           = null,
    ): ?array {
        $engineService = self::SERVICE_MAP[$serviceSlug] ?? null;
        if ($engineService === null) {
            Log::warning('SchedulingEngineService: unknown service slug', ['slug' => $serviceSlug]);
            return null;
        }

        $payload = $this->buildPayload(
            $engineService,
            $date,
            $startTime,
            $endTime,
            $providerId,
            $excludeBookingId,
        );

        // History-based Stage 2: kung walang history, hindi tatakbo ang Stage 2 sa Python.
        $history = $this->buildHistory($serviceSlug, $userId);
        if ($history !== null) {
            $payload['history'] = $history;
        }

        $result = $this->post($payload);
        if ($result === null) {
            return null;
        }

        // Kailangan ng caller ang id ng candidate para mahanap ang ILP assignment nito.
        $result['_candidate_id'] = $payload['candidate']['booking_id'];

        if (($result['job_status'] ?? null) !== 'COMPLETED') {
            Log::warning('SchedulingEngineService: engine did not complete', [
                'job_status' => $result['job_status'] ?? null,
                'invalid'    => $result['invalid'] ?? null,
                'reason'     => $result['reason'] ?? null,
            ]);
        }

        return $result;
    }

    // ---------------------------------------------------------------- history

    /**
     * Pinakabagong completed (o approved at tapos na) appointment ng client para sa parehong service.
     * Returns ['weekday' => 1..7 (ISO), 'start' => 'HH:MM'] o null.
     */
    public function buildHistory(string $serviceSlug, ?int $userId): ?array
    {
        if (!$userId) {
            return null;
        }

        // "Completed" = minarkahan ng Admin na Done (completed_at), O approved na at tapos na ang oras.
        // (Ganito rin ang nakikita ng client sa "Completed appointments".)
        // end_at is Asia/Manila wall clock — compare against Manila now.
        $nowManila = now('Asia/Manila')->format('Y-m-d H:i:s');

        $last = BookingPayment::where('user_id', $userId)
            ->where('service_id', $serviceSlug)
            ->whereNull('cancelled_at')
            ->where('status', '!=', 'rejected')
            ->where(function ($w) use ($nowManila) {
                $w->whereNotNull('completed_at')
                  ->orWhere(function ($x) use ($nowManila) {
                      $x->where('approval_status', 'approved')
                        ->where('end_at', '<', $nowManila);
                  });
            })
            ->orderByDesc('start_at')
            ->first();

        if (!$last) {
            return null;
        }

        $start = Carbon::parse($last->start_at);

        return [
            'weekday' => $start->dayOfWeekIso,
            'start'   => $start->format('H:i'),
        ];
    }

    // ---------------------------------------------------------------- payload builder

    private function buildPayload(
        string $engineService,
        string $date,
        string $startTime,
        string $endTime,
        ?int   $providerId,
        ?int   $excludeBookingId,
    ): array {
        // Engine treats run_at as wall-clock "now" for past-slot filtering.
        // Booking inventory is Asia/Manila wall clock, so send Manila time.
        $runAt = now('Asia/Manila')->toIso8601String();

        // --- Providers ---
        $providers = $this->buildProviders($date);

        // --- Active bookings ---
        $activeBookings = $this->buildActiveBookings(
            $date,
            $excludeBookingId,
            array_column($providers, 'provider_id'),
        );

        // --- Candidate ---
        $candidate = $this->buildCandidate(
            $engineService,
            $date,
            $startTime,
            $endTime,
            $providerId,
        );

        return [
            'schema_version'  => '2.0',
            'job_id'          => 'job_' . Str::random(12),
            'run_at'          => $runAt,
            'config'          => [
                'horizon_days'          => self::HORIZON_DAYS,
                'slot_minutes'          => 30,
                'max_recommendations'   => 3,
                'max_candidate_checks'  => 500,
                'time_limit_ms'         => 2000,
                'mip_gap'               => 0.0,
                'max_bookings'          => 500,
            ],
            'providers'       => $providers,
            'active_bookings' => $activeBookings,
            'candidate'       => $candidate,
        ];
    }

    // ---------------------------------------------------------------- providers

    private function buildProviders(string $candidateDate): array
    {
        // Query User first (has role + is_active), then join to Provider via user_id.
        // Provider model has no user() relationship, so we avoid with()/whereHas().
        // Lahat ng active ADMIN + AFFILIATE ang ipinapadala, kahit anong service ang candidate.
        // Kailangan ito para kilala ng engine ang pinned PC bookings (kahit affiliate) at
        // para makita ng ILP ang lahat ng Admin kapag ABA/AC ang inaayos.
        $users = User::where('is_active', true)
            ->whereIn('role', ['ADMIN', 'AFFILIATE'])
            ->get()
            ->keyBy('id');  // keyed by user_id for fast lookup

        $dbProviders = Provider::whereIn('user_id', $users->keys())->get();

        // Availability window: nakaangkla sa petsa ng candidate (hindi sa "today"),
        // para hindi mawala ang availability ng malalayong petsa.
        $windowStart = Carbon::parse($candidateDate)->startOfDay();
        $windowEnd   = $windowStart->copy()->addDays(self::HORIZON_DAYS + 1)->endOfDay();

        $availabilities = ProviderAvailability::whereIn('provider_id', $dbProviders->pluck('id'))
            ->where('status', 'available')
            ->where('start_at', '<', $windowEnd)
            ->where('end_at', '>', $windowStart)
            ->get()
            ->groupBy('provider_id');

        $providers = [];

        foreach ($dbProviders as $p) {
            $user = $users->get($p->user_id);
            $role = match ($user->role ?? '') {
                'ADMIN'     => 'ADMIN_PSYCHOLOGIST',
                'AFFILIATE' => 'AFFILIATE',
                default     => 'ADMIN_PSYCHOLOGIST',
            };

            $eligibleServices = ($role === 'AFFILIATE') ? ['PC'] : ['PC', 'ABA', 'AC'];

            $avail = [];
            foreach ($availabilities->get($p->id, collect()) as $a) {
                $avail[] = [
                    'date'  => Carbon::parse($a->start_at)->format('Y-m-d'),
                    'start' => Carbon::parse($a->start_at)->format('H:i'),
                    'end'   => Carbon::parse($a->end_at)->format('H:i'),
                ];
            }

            $providers[] = [
                'provider_id'       => (string) $p->id,
                'role'              => $role,
                'eligible_services' => $eligibleServices,
                'availability'      => $avail,
            ];
        }

        return $providers;
    }

    // ---------------------------------------------------------------- active bookings

    private function buildActiveBookings(
        string $candidateDate,
        ?int   $excludeBookingId,
        array  $knownProviderIds,
    ): array {
        // Same window as availability, para laging nasa loob ng availability ang bookings
        $windowStart = Carbon::parse($candidateDate)->startOfDay();
        $windowEnd   = $windowStart->copy()->addDays(self::HORIZON_DAYS + 1)->endOfDay();

        $query = BookingPayment::whereIn('status', ['to_verify', 'verified'])
            ->where('approval_status', '!=', 'declined')
            ->whereNull('cancelled_at')
            ->where('start_at', '<', $windowEnd)
            ->where('end_at', '>', $windowStart);

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        // Lahat ng service ang kasama: ang PC ng isang Admin ay humaharang sa ABA/AC niya,
        // at ang ABA/AC ay humaharang sa PC niya.
        $query->whereIn('service_id', array_keys(self::SERVICE_MAP));

        $bookings = $query->get();

        $result = [];
        foreach ($bookings as $b) {
            $engineSvc = self::SERVICE_MAP[$b->service_id] ?? null;
            if ($engineSvc === null) {
                continue;
            }
            // Walang kilalang provider (inactive/na-delete) -> huwag isama, INVALID_STATE ang magiging resulta
            if (!$b->provider_id || !in_array((string) $b->provider_id, $knownProviderIds, true)) {
                Log::warning('SchedulingEngineService: booking skipped, unknown provider', [
                    'booking_id' => $b->id, 'provider_id' => $b->provider_id,
                ]);
                continue;
            }

            $status = self::BOOKING_STATUS_MAP[$b->status] ?? 'PAID';

            $result[] = [
                'booking_id'          => (string) $b->id,
                'service'             => $engineSvc,
                'date'                => Carbon::parse($b->start_at)->format('Y-m-d'),
                'start'               => Carbon::parse($b->start_at)->format('H:i'),
                'end'                 => Carbon::parse($b->end_at)->format('H:i'),
                'pinned_provider_id'  => $engineSvc === 'PC' ? (string) $b->provider_id : null,
                'assigned_admin_id'   => $engineSvc !== 'PC' ? (string) $b->provider_id : null,
                'status'              => $status,
            ];
        }

        return $result;
    }

    // ---------------------------------------------------------------- candidate

    private function buildCandidate(
        string  $engineService,
        string  $date,
        string  $startTime,
        string  $endTime,
        ?int    $providerId,
    ): array {
        $candidate = [
            'booking_id' => 'cand_' . Str::random(8),
            'service'    => $engineService,
            'date'       => $date,
            'start'      => $startTime,
            'end'        => $endTime,
        ];

        if ($engineService === 'PC' && $providerId) {
            $candidate['pinned_provider_id'] = (string) $providerId;
        } else {
            $candidate['pinned_provider_id'] = null;
        }

        return $candidate;
    }

    // ---------------------------------------------------------------- HTTP

    private function post(array $payload): ?array
    {
        $url   = rtrim(config('services.scheduling_engine.url'), '/') . '/api/v1/algorithm/solve';
        $token = config('services.scheduling_engine.token');

        try {
            $response = Http::withHeaders([
                'X-Service-Token' => $token,
                'Accept'          => 'application/json',
            ])
                ->timeout(10)
                ->post($url, $payload);

            if ($response->successful() || $response->status() === 413) {
                return $response->json();
            }

            Log::warning('SchedulingEngineService: non-success response', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('SchedulingEngineService: HTTP error', [
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    // ---------------------------------------------------------------- helpers (static, usable from controllers)

    /**
     * True when the engine says the candidate is schedulable.
     */
    public static function isFeasible(?array $result): bool
    {
        return isset($result['decision']) && $result['decision'] === 'FEASIBLE';
    }

    /**
     * True when the engine returned NOT_FEASIBLE (slot conflict detected).
     */
    public static function isNotFeasible(?array $result): bool
    {
        return isset($result['decision']) && $result['decision'] === 'NOT_FEASIBLE';
    }

    /**
     * Ang Admin (Provider id) na pinili ng ILP para sa candidate ABA/AC.
     * Null kung walang assignment sa response.
     */
    public static function assignedAdminProviderId(?array $result): ?int
    {
        $candId = $result['_candidate_id'] ?? null;
        if ($candId === null) {
            return null;
        }
        foreach ($result['stage1']['assignment'] ?? [] as $row) {
            if (($row['booking_id'] ?? null) === $candId) {
                return (int) $row['admin_id'];
            }
        }
        return null;
    }

    /**
     * Reassignments ng ILP: [['booking_id' => '12', 'from_admin_id' => '1', 'to_admin_id' => '2'], ...]
     */
    public static function reassignments(?array $result): array
    {
        return $result['stage1']['reassignments'] ?? [];
    }

    /**
     * Extract the first recommendation from the engine response (if any).
     * Returns ['date', 'start', 'end', 'provider_id'] or null.
     */
    public static function firstRecommendation(?array $result): ?array
    {
        $recs = $result['recommendations'] ?? [];
        foreach ($recs as $rec) {
            if (($rec['status'] ?? '') === 'RECOMMENDATION_FOUND') {
                return $rec;
            }
        }
        return null;
    }

    /**
     * Extract all RECOMMENDATION_FOUND entries from the engine response.
     * Returns array of ['rank', 'tier', 'date', 'start', 'end', 'provider_id'].
     */
    public static function allRecommendations(?array $result): array
    {
        $recs = $result['recommendations'] ?? [];
        return array_values(array_filter($recs, fn ($r) => ($r['status'] ?? '') === 'RECOMMENDATION_FOUND'));
    }
}