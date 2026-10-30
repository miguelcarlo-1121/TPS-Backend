<?php

namespace App\Http\Controllers;

use App\Models\BookingPayment;
use App\Models\ClientProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    private const SERVICE_NAMES = [
        'psychotherapy' => 'Psychotherapy',
        'aba'           => 'ABA Therapy',
        'assessment'    => 'Assessment',
    ];

    /**
     * Parehong rule sa Appointments:
     * Psychotherapy = psychologist na pinili lang. ABA / Assessment = lahat ng ADMIN.
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
        if ($p->status === 'to_verify') return 'verify';
        if ($p->approval_status === 'declined') return 'declined';
        if ($p->completed_at) return 'completed';
        if ($p->approval_status === 'approved') return 'approved';
        return 'pending';
    }

    private function monthKeys(int $n): array
    {
        $first = now()->startOfMonth();
        $keys  = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $keys[] = $first->copy()->subMonths($i)->format('Y-m');
        }
        return $keys;
    }

    private function pct($cur, $prev): ?float
    {
        if ($prev == 0) return $cur > 0 ? 100.0 : null;
        return round(($cur - $prev) / $prev * 100, 1);
    }

    private function names($rows): array
    {
        $ids      = $rows->pluck('user_id')->unique();
        $profiles = ClientProfile::whereIn('user_id', $ids)->pluck('full_name', 'user_id');
        $users    = User::whereIn('id', $ids)->get()->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            $u = $users[$id] ?? null;
            $out[$id] = $profiles[$id] ?? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'Client';
        }
        return $out;
    }

    private function peso(float $n): string
    {
        return '₱' . number_format($n, $n == floor($n) ? 0 : 2);
    }

    // GET /api/admin/dashboard
    public function index(Request $request)
    {
        $user = $request->user();
        $keys = $this->monthKeys(12);
        $cur  = $keys[11];
        $prev = $keys[10];
        $from = now()->startOfMonth()->subMonths(11);

        // ---------- Income ng buong clinic (verified payments lang) ----------
        $incomeBase = $user->role === 'AFFILIATE'
    ? $this->scoped($user)
    : BookingPayment::query();

$paid = $incomeBase
    ->where('status', 'verified')
    ->where('approval_status', '!=', 'declined')
    ->where(function ($q) use ($from) {
        $q->where('reviewed_at', '>=', $from)
          ->orWhere(fn ($w) => $w->whereNull('reviewed_at')->where('created_at', '>=', $from));
    })
    ->get(['amount_paid', 'service_id', 'reviewed_at', 'created_at']);

        $incomeByMonth = $paid
            ->groupBy(fn ($p) => ($p->reviewed_at ?? $p->created_at)->format('Y-m'))
            ->map(fn ($g) => (float) $g->sum('amount_paid'));

        $monthly = array_map(fn ($k) => [
            'key'   => $k,
            'label' => Carbon::createFromFormat('!Y-m', $k)->format('M'),
            'total' => (float) ($incomeByMonth[$k] ?? 0),
        ], $keys);

        $best = collect($monthly)->sortByDesc('total')->first();

        // Income ngayong buwan kada service
        $thisMonth = $paid->filter(fn ($p) => ($p->reviewed_at ?? $p->created_at)->format('Y-m') === $cur);
        $services  = [];
        foreach (self::SERVICE_NAMES as $id => $name) {
            if ($user->role === 'AFFILIATE' && $id !== 'psychotherapy') continue;
            $rows = $thisMonth->where('service_id', $id);
            $services[] = [
                'id'       => $id,
                'name'     => $name,
                'amount'   => (float) $rows->sum('amount_paid'),
                'sessions' => $rows->count(),
            ];
        }

        // ---------- Personal (ayon sa appointments ng user na ito) ----------
        $rows = $this->scoped($user)->where('start_at', '>=', $from)->get();

        $isReal = fn ($p) => in_array($this->state($p), ['completed', 'approved'], true);

        $sessionsByMonth = $rows->filter($isReal)
            ->groupBy(fn ($p) => $p->start_at->format('Y-m'))
            ->map->count();

        // New clients = unang beses mag-appointment sa clinic (hindi new login)
        $firstAt = $this->scoped($user)
            ->selectRaw('user_id, MIN(created_at) as first_at')
            ->groupBy('user_id')
            ->get();

        $newByMonth = $firstAt
            ->groupBy(fn ($r) => Carbon::parse($r->first_at)->format('Y-m'))
            ->map->count();

        $series = fn ($map) => array_map(fn ($k) => (int) ($map[$k] ?? 0), array_slice($keys, 6));

        // Your sessions (ngayong buwan)
        $monthRows = $rows->filter(fn ($p) => $p->start_at->format('Y-m') === $cur);
        $counts = ['completed' => 0, 'confirmed' => 0, 'pending' => 0, 'cancelled' => 0];
        foreach ($monthRows as $p) {
            match ($this->state($p)) {
                'completed' => $counts['completed']++,
                'approved'  => $counts['confirmed']++,
                'declined'  => $counts['cancelled']++,
                default     => $counts['pending']++,
            };
        }
        $totalMonth = array_sum($counts) ?: 1;
        $yourSessions = [];
        foreach ($counts as $k => $n) {
            $yourSessions[] = ['key' => $k, 'n' => $n, 'pct' => (int) round($n / $totalMonth * 100)];
        }

        // Needs attention
        $toVerify = $this->scoped($user)->where('status', 'to_verify')->count();
        $awaiting = $this->scoped($user)->where('status', 'verified')->where('approval_status', 'pending')->count();

        // Today's sessions
        $todayRows = $rows->filter(fn ($p) => $p->start_at->isToday() && $isReal($p))
            ->sortBy('start_at')->values();
        $recent = $this->scoped($user)->orderByDesc('created_at')->limit(6)->get();
        $names  = $this->names($todayRows->concat($recent));

        $now   = now();
        $today = $todayRows->map(function ($p) use ($names, $now) {
            $status = $p->completed_at ? 'Done'
                : ($now->between($p->start_at, $p->end_at) ? 'In Session'
                : ($now->lt($p->start_at) ? 'Upcoming' : 'Ended'));

            return [
                'id'           => $p->id,
                'client_name'  => $names[$p->user_id] ?? 'Client',
                'service_name' => self::SERVICE_NAMES[$p->service_id] ?? $p->service_id,
                'start_time'   => $p->start_at->format('H:i'),
                'end_time'     => $p->end_at->format('H:i'),
                'status'       => $status,
            ];
        })->values();

        $activity = $recent->map(fn ($p) => [
            'id'   => $p->id,
            'text' => ($names[$p->user_id] ?? 'Client') . ' paid ' . $this->peso((float) $p->amount_paid) . ' via ' . $p->method_name,
            'at'   => $p->created_at->toIso8601String(),
        ])->values();

        return response()->json([
            'user' => ['first_name' => $user->first_name, 'last_name' => $user->last_name],

            'income' => [
                'this_month'    => $monthly[11]['total'],
                'delta_pct'     => $this->pct($monthly[11]['total'], $monthly[10]['total']),
                'series'        => array_map(fn ($m) => $m['total'], array_slice($monthly, 6)),
                'total_12'      => (float) array_sum(array_column($monthly, 'total')),
                'average_12'    => round(array_sum(array_column($monthly, 'total')) / 12, 2),
                'best'          => $best && $best['total'] > 0 ? ['label' => $best['key'], 'total' => $best['total']] : null,
                'monthly'       => $monthly,
                'services'      => $services,
            ],

            'new_clients' => [
                'this_month' => (int) ($newByMonth[$cur] ?? 0),
                'delta_pct'  => $this->pct($newByMonth[$cur] ?? 0, $newByMonth[$prev] ?? 0),
                'series'     => $series($newByMonth),
            ],

            'sessions' => [
                'this_month' => (int) ($sessionsByMonth[$cur] ?? 0),
                'delta_pct'  => $this->pct($sessionsByMonth[$cur] ?? 0, $sessionsByMonth[$prev] ?? 0),
                'series'     => $series($sessionsByMonth),
                'breakdown'  => $yourSessions,
                'month_total' => $monthRows->count(),
            ],

            'attention' => [
                'to_verify' => $toVerify,
                'awaiting'  => $awaiting,
                'total'     => $toVerify + $awaiting,
            ],

            'today'    => $today,
            'activity' => $activity,
        ]);
    }

    // GET /api/admin/dashboard/calendar?month=2026-10
    public function calendar(Request $request)
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        $start = Carbon::createFromFormat('!Y-m', $data['month'])->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        $rows = $this->scoped($request->user())
            ->where('approval_status', '!=', 'declined')
            ->whereBetween('start_at', [$start, $end])
            ->orderBy('start_at')
            ->get();

        $names = $this->names($rows);

        return response()->json([
            'appointments' => $rows->map(fn ($p) => [
                'id'           => $p->id,
                'date'         => $p->start_at->format('Y-m-d'),
                'start_time'   => $p->start_at->format('H:i'),
                'end_time'     => $p->end_at->format('H:i'),
                'client_name'  => $names[$p->user_id] ?? 'Client',
                'service_name' => self::SERVICE_NAMES[$p->service_id] ?? $p->service_id,
                'state'        => $this->state($p),
            ])->values(),
        ]);
    }
}