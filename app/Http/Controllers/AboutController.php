<?php

namespace App\Http\Controllers;

use App\Models\HomeContent;
use App\Models\User;
use Illuminate\Support\Collection;

class AboutController extends Controller
{
    private const DEFAULT_HEADLINE = 'Care that listens first.';

    private const DEFAULT_INTRO = 'Threefold Behavioral Interventions and Psychological Services is a clinic in Pasig that offers psychotherapy and counseling, ABA therapy, and assessment in a safe, supportive environment. Your wellbeing comes first, and you move at your own pace.';

    /** Ang "about" na nakasave sa home content (headline, intro, members). */
    private function settings(): array
    {
        $row   = HomeContent::where('key', 'home')->first();
        $about = data_get($row?->content, 'about', []);

        return is_array($about) ? $about : [];
    }

    /**
     * Lahat ng active ADMIN at AFFILIATE, kasama ang settings ni admin.
     * Awtomatiko: kapag may bagong admin/affiliate account, lalabas agad dito.
     * Ang order ay ayon sa naka-save; ang bago ay napupunta sa dulo.
     */
    private function members(array $about): Collection
    {
        $saved = collect($about['members'] ?? [])->filter(fn ($m) => is_array($m) && isset($m['user_id']))->values();
        $order = $saved->pluck('user_id')->flip();
        $byId  = $saved->keyBy('user_id');

        $users = User::whereIn('role', ['ADMIN', 'AFFILIATE'])
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
            ->orderByRaw("CASE role WHEN 'ADMIN' THEN 0 ELSE 1 END")
            ->orderBy('first_name')
            ->get();

        return $users
            ->sortBy(fn (User $u) => $order->get($u->id, PHP_INT_MAX))
            ->values()
            ->map(function (User $u) use ($byId) {
                $s = $byId->get($u->id, []);

                return [
                    'user_id'       => $u->id,
                    'first_name'    => $u->first_name,
                    'last_name'     => $u->last_name,
                    'name'          => trim($u->first_name . ' ' . $u->last_name),
                    'role'          => $u->role,
                    'avatar_url'    => $u->avatar_url,
                    'background'    => $u->background,
                    'title'         => (string) ($s['title'] ?? ''),
                    'visible'       => (bool) ($s['visible'] ?? true),
                ];
            });
    }

    // GET /api/about/team  (public, walang login)
    public function team()
    {
        $about = $this->settings();

        // Public ito, kaya ang mga ligtas na field lang ang ilalabas
        // (walang email, phone, o iba pang private na data).
        $team = $this->members($about)
            ->filter(fn ($m) => $m['visible'])
            ->map(fn ($m) => [
                'id'         => $m['user_id'],
                'first_name' => $m['first_name'],
                'last_name'  => $m['last_name'],
                'title'      => $m['title'],
                'avatar_url' => $m['avatar_url'],
                'background' => $m['background'],
            ])
            ->values();

        return response()->json([
            'about' => [
                'headline' => $about['headline'] ?? self::DEFAULT_HEADLINE,
                'intro'    => $about['intro'] ?? self::DEFAULT_INTRO,
            ],
            'team' => $team,
        ]);
    }

    // GET /api/admin/about-team  (ADMIN lang)
    public function adminTeam()
    {
        $about = $this->settings();

        return response()->json([
            'about' => [
                'headline' => $about['headline'] ?? self::DEFAULT_HEADLINE,
                'intro'    => $about['intro'] ?? self::DEFAULT_INTRO,
            ],
            'defaults' => [
                'headline' => self::DEFAULT_HEADLINE,
                'intro'    => self::DEFAULT_INTRO,
            ],
            'members' => $this->members($about),
        ]);
    }
}