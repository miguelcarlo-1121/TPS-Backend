<?php

namespace App\Http\Controllers;

use App\Mail\EmailChangeCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $rules = [
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'phone'      => 'nullable|string|max:30',
        ];

        if (in_array($user->role, ['ADMIN', 'AFFILIATE'], true)) {
            $rules['background'] = 'nullable|string|max:5000';
        }

        $data = $request->validate($rules);

        $user->forceFill($data)->save();

        return response()->json(['user' => $user->fresh()]);
    }

    public function requestEmailChange(Request $request)
    {
        $user = $request->user();

        if ($user->login_provider !== 'SYSTEM') {
            return response()->json([
                'message' => 'Email can only be changed for email/password accounts.',
            ], 422);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
        ]);

        $newEmail = strtolower(trim($data['email']));

        if ($newEmail === strtolower($user->email)) {
            throw ValidationException::withMessages([
                'email' => 'That is already your current email address.',
            ]);
        }

        if (User::where('email', $newEmail)->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'That email is already used by another account.',
            ]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'pending_email' => $newEmail,
            'pending_email_code' => Hash::make($code),
            'pending_email_expires_at' => now()->addMinutes(15),
            'pending_email_attempts' => 0,
        ])->save();

        Mail::to($newEmail)->send(new EmailChangeCode($code, $user->first_name ?: 'there'));

        return response()->json([
            'message' => 'We sent a 6-digit code to the new address.',
            'email' => $newEmail,
        ]);
    }

    public function confirmEmailChange(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $newEmail = strtolower(trim($data['email']));

        if (!$user->pending_email || $user->pending_email !== $newEmail) {
            throw ValidationException::withMessages([
                'code' => 'No pending email change for this address. Please start over.',
            ]);
        }

        if (!$user->pending_email_expires_at || $user->pending_email_expires_at->isPast()) {
            $user->forceFill([
                'pending_email' => null,
                'pending_email_code' => null,
                'pending_email_expires_at' => null,
                'pending_email_attempts' => 0,
            ])->save();

            throw ValidationException::withMessages([
                'code' => 'That code expired. Please request a new one.',
            ]);
        }

        if ($user->pending_email_attempts >= 5) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Please request a new code.',
            ]);
        }

        if (!Hash::check($data['code'], $user->pending_email_code)) {
            $user->increment('pending_email_attempts');
            throw ValidationException::withMessages([
                'code' => 'That code is not correct.',
            ]);
        }

        $user->forceFill([
            'email' => $newEmail,
            'email_verified_at' => now(),
            'pending_email' => null,
            'pending_email_code' => null,
            'pending_email_expires_at' => null,
            'pending_email_attempts' => 0,
        ])->save();

        // Return the same shape as show() / update() so the frontend gets a consistent user object.
        return response()->json([
            'message' => 'Email updated.',
            'user' => $user->fresh(),
        ]);
    }

    // Receives a small square JPEG as a base64 data URL (the page resizes it first)
    public function avatar(Request $request)
    {
        $request->validate(['image' => 'required|string']);

        if (!preg_match('/^data:image\/jpeg;base64,/', $request->image)) {
            return response()->json(['message' => 'Please upload a JPG, PNG or WEBP image.'], 422);
        }

        $binary = base64_decode(substr($request->image, strpos($request->image, ',') + 1), true);

        if ($binary === false || strlen($binary) > 2 * 1024 * 1024 || @getimagesizefromstring($binary) === false) {
            return response()->json(['message' => 'That image could not be used. Try a smaller one.'], 422);
        }

        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = 'avatars/' . $user->id . '-' . time() . '.jpg';
        Storage::disk('public')->put($path, $binary);

        $user->forceFill(['avatar_path' => $path])->save();

        return response()->json(['user' => $user->fresh()]);
    }

    public function removeAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return response()->json(['user' => $user->fresh()]);
    }

    public function password(Request $request)
    {
        $user = $request->user();

        if ($user->login_provider !== 'SYSTEM') {
            return response()->json([
                'message' => 'This account signs in with Google or Facebook, so it has no password to change.',
            ], 403);
        }

        $data = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Your current password is incorrect.'], 422);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        return response()->json(['message' => 'Password updated.']);
    }
}