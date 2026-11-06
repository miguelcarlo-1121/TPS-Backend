<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

class PsychologistController extends Controller
{
    /**
     * Public list ng lahat ng active na ADMIN at AFFILIATE
     * para sa psychologist selection sa Book Session (Psychotherapy lang).
     */
    public function index()
    {
        $psychologists = User::whereIn('role', ['ADMIN', 'AFFILIATE'])
            ->where('is_active', true)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($u) => [
                'id'         => $u->id,
                'first_name' => $u->first_name,
                'last_name'  => $u->last_name,
                'name'       => trim($u->first_name . ' ' . $u->last_name),
                'role'       => $u->role,
                'title'      => $u->role === 'ADMIN' ? 'Clinical Director & Lead Psychologist' : 'Licensed Psychologist & Affiliate',
                'email'      => $u->email,
                'phone'      => $u->phone,
                'avatar_url' => $u->avatar_url
                    ?? ($u->avatar_path ? Storage::disk('public')->url($u->avatar_path) : null),
            ])
            ->values();

        return response()->json(['psychologists' => $psychologists]);
    }

    public function show($id)
    {
        $u = User::whereIn('role', ['ADMIN', 'AFFILIATE'])
            ->where('is_active', true)
            ->findOrFail($id);

        return response()->json([
            'psychologist' => [
                'id'         => $u->id,
                'first_name' => $u->first_name,
                'last_name'  => $u->last_name,
                'name'       => trim($u->first_name . ' ' . $u->last_name),
                'role'       => $u->role,
                'title'      => $u->role === 'ADMIN' ? 'Clinical Director & Lead Psychologist' : 'Licensed Psychologist & Affiliate',
                'email'      => $u->email,
                'phone'      => $u->phone,
                'avatar_url' => $u->avatar_url
                    ?? ($u->avatar_path ? Storage::disk('public')->url($u->avatar_path) : null),
            ]
        ]);
    }
}