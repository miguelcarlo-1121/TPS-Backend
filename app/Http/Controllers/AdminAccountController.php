<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminAccountController extends Controller
{
    /**
     * GET /api/admin/accounts
     * Query params:
     *  - role: "All" | "Client" | "Psychologist" | "Affiliate" | "Admin"
     *  - q: search term (name, email, phone)
     *  - page: int
     *  - per_page: int (default 10)
     */
    public function index(Request $request)
    {
        $query = User::query();

        $role = $request->input('role', 'All');
        if ($role && strtolower($role) !== 'all') {
            $upper = strtoupper($role);
            if ($upper === 'PSYCHOLOGIST') {
                $query->whereIn('role', ['AFFILIATE', 'ADMIN']);
            } else {
                $query->where('role', $upper);
            }
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($w) use ($q) {
                $w->where('first_name', 'like', "%{$q}%")
                  ->orWhere('last_name', 'like', "%{$q}%")
                  ->orWhere('email', 'like', "%{$q}%")
                  ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        $perPage = max(1, min(50, (int) $request->input('per_page', 10)));
        $paginator = $query->orderByDesc('id')->paginate($perPage);

        $accounts = collect($paginator->items())->map(function (User $u) {
            $roleLabel = match ($u->role) {
                'ADMIN'     => 'Admin',
                'AFFILIATE' => 'Affiliate',
                default     => 'Client',
            };

            return [
                'id'         => $u->id,
                'name'       => trim($u->first_name . ' ' . $u->last_name),
                'first_name' => $u->first_name,
                'last_name'  => $u->last_name,
                'email'      => $u->email,
                'phone'      => $u->phone ?: '—',
                'role'       => $roleLabel,
                'raw_role'   => $u->role,
                'status'     => $u->is_active ? 'Active' : 'Suspended',
                'joined'     => $u->created_at ? $u->created_at->format('M d, Y') : '—',
                'avatar_url' => $u->avatar_url,
            ];
        });

        return response()->json([
            'accounts' => $accounts,
            'meta'     => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * POST /api/admin/accounts
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|min:2|max:100',
            'last_name'  => 'required|string|min:2|max:100',
            'email'      => 'required|email|max:255|unique:users,email',
            'phone'      => 'nullable|string|regex:/^09\d{9}$/',
            'role'       => 'required|in:CLIENT,AFFILIATE,ADMIN',
            'password'   => 'required|string|min:8',
        ], [
            'phone.regex' => 'Phone number must be 11 digits starting with 09 (e.g. 09123456789).',
        ]);

        $user = User::create([
            'first_name' => trim($data['first_name']),
            'last_name'  => trim($data['last_name']),
            'email'      => strtolower(trim($data['email'])),
            'phone'      => $data['phone'] ? trim($data['phone']) : null,
            'role'       => $data['role'],
            'password'   => $data['password'],
            'is_active'  => true,
            'email_verified_at' => now(),
        ]);

        return response()->json([
            'message' => 'Account created successfully.',
            'account' => [
                'id'    => $user->id,
                'name'  => trim($user->first_name . ' ' . $user->last_name),
                'email' => $user->email,
                'role'  => $user->role,
            ],
        ], 201);
    }

    /**
     * PUT /api/admin/accounts/{id}
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'first_name' => 'required|string|min:2|max:100',
            'last_name'  => 'required|string|min:2|max:100',
            'email'      => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'      => 'nullable|string|regex:/^09\d{9}$/',
            'role'       => 'required|in:CLIENT,AFFILIATE,ADMIN',
            'password'   => 'nullable|string|min:8',
            'status'     => 'nullable|in:Active,Suspended',
        ], [
            'phone.regex' => 'Phone number must be 11 digits starting with 09 (e.g. 09123456789).',
        ]);

        $user->first_name = trim($data['first_name']);
        $user->last_name  = trim($data['last_name']);
        $user->email      = strtolower(trim($data['email']));
        $user->phone      = !empty($data['phone']) ? trim($data['phone']) : null;
        $user->role       = $data['role'];

        if (!empty($data['password'])) {
            $user->password = $data['password'];
        }

        if (isset($data['status'])) {
            $user->is_active = ($data['status'] === 'Active');
        }

        $user->save();

        return response()->json([
            'message' => 'Account updated successfully.',
            'account' => [
                'id'    => $user->id,
                'name'  => trim($user->first_name . ' ' . $user->last_name),
                'email' => $user->email,
                'role'  => $user->role,
            ],
        ]);
    }

    /**
     * POST /api/admin/accounts/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $request->validate(['status' => 'required|in:Active,Suspended,Pending']);

        $user->is_active = ($request->input('status') === 'Active');
        $user->save();

        return response()->json([
            'message' => 'Account status updated.',
            'status'  => $user->is_active ? 'Active' : 'Suspended',
        ]);
    }

    /**
     * DELETE /api/admin/accounts/{id}
     */
    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($request->user() && $request->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own admin account.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'Account deleted successfully.']);
    }
}
