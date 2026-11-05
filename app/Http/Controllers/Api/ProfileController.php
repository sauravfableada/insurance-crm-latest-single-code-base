<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ProfileController extends ApiBaseController
{
    public function show(Request $request)
    {
        return $this->success($request->user());
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'nullable|string|max:255|unique:users,username,'.$user->id,
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'phone' => 'nullable|string|max:20',
            'job_title' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
        ]);

        $oldEmail = $user->getOriginal('email');
        $user->update($validated);

        if ($user->isAdmin()) {
            try {
                app(\App\Services\Tenancy\AdminErpSyncService::class)->syncClientProfile($user, $request->only(['company', 'city', 'country']), $oldEmail);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $this->success($user, 'Profile updated successfully');
    }

    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => [
                'required',
                function ($attribute, $value, $fail) use ($request) {
                    $user = $request->user();
                    $valid = $user && (
                        Hash::check($value, $user->password) ||
                        hash_equals((string) $user->password, (string) $value)
                    );
                    if (!$valid) {
                        $fail(__('The provided password does not match your current password.'));
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();
        $userUpdate = ['password' => Hash::make($validated['password'])];
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'pass_dec')) {
            $userUpdate['pass_dec'] = $validated['password'];
        }
        $user->update($userUpdate);

        if ($user->isAdmin() || $user->id === 1 || empty($user->parent_id)) {
            try {
                app(\App\Services\Tenancy\AdminErpSyncService::class)->syncClientPassword($user, $validated['password']);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $this->success(null, 'Password updated successfully');
    }
}
