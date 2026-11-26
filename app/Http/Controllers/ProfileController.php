<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\GoogleCalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    private function googleService(): ?GoogleCalendarService
    {
        try {
            return app(GoogleCalendarService::class);
        } catch (\Throwable $e) {
            Log::warning('Google Calendar service unavailable', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function show()
    {
        $user = Auth::user();
        $settings = $this->profileSettings();
        $kyc = \App\Models\KycDocumentsModel::where('user_id', $user->id)
            ->whereNull('policy_id')
            ->whereNull('lead_id')
            ->first();

        if (!$kyc) {
            // Also fallback to any kyc record by this user
            $kyc = \App\Models\KycDocumentsModel::where('user_id', $user->id)->latest('id')->first();
        }

        return view('profile.show', compact('user', 'settings', 'kyc'));
    }

    public function updateKyc(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'aadhaar_card.*' => 'nullable|file|mimes:jpeg,png,jpg,pdf,jfif|max:5120',
            'pan_card.*'     => 'nullable|file|mimes:jpeg,png,jpg,pdf,jfif|max:5120',
            'passbook.*'     => 'nullable|file|mimes:jpeg,png,jpg,pdf,jfif|max:5120',
            'cheque.*'       => 'nullable|file|mimes:jpeg,png,jpg,pdf,jfif|max:5120',
            'aadhaar_number' => 'nullable|string|max:50',
            'pan_number'     => 'nullable|string|max:50',
            'account_number' => 'nullable|string|max:50',
            'cheque_number'  => 'nullable|string|max:50',
        ], [
            'aadhaar_card.*.max' => 'Each Aadhaar Card file must not exceed 5 MB.',
            'pan_card.*.max'     => 'Each PAN Card file must not exceed 5 MB.',
            'passbook.*.max'     => 'Each Passbook file must not exceed 5 MB.',
            'cheque.*.max'       => 'Each Cheque file must not exceed 5 MB.',
        ]);

        $uploadDir = public_path('uploads/kyc');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $kyc = \App\Models\KycDocumentsModel::where('user_id', $user->id)
            ->whereNull('policy_id')
            ->whereNull('lead_id')
            ->first();

        if (!$kyc) {
            $kyc = new \App\Models\KycDocumentsModel();
            $kyc->user_id = $user->id;
            $kyc->kyc_status = 'submitted';
            $kyc->created_at = now();
        }

        $kyc->updated_at = now();

        // Update document number fields if present in request
        if ($request->has('aadhaar_number')) {
            $kyc->aadhaar_number = trim((string) $request->input('aadhaar_number')) ?: null;
        }
        if ($request->has('pan_number')) {
            $kyc->pan_number = trim((string) $request->input('pan_number')) ?: null;
        }
        if ($request->has('account_number')) {
            $kyc->account_number = trim((string) $request->input('account_number')) ?: null;
        }
        if ($request->has('cheque_number')) {
            $kyc->cheque_number = trim((string) $request->input('cheque_number')) ?: null;
        }

        $fields = ['aadhaar_card', 'pan_card', 'passbook', 'cheque'];

        foreach ($fields as $field) {
            if ($request->hasFile($field)) {
                $saved = [];
                // Retrieve existing files if any
                $existingVal = $kyc->{$field};
                if (!empty($existingVal)) {
                    $decoded = is_string($existingVal) ? json_decode($existingVal, true) : (array) $existingVal;
                    if (is_array($decoded)) {
                        $saved = $decoded;
                    } elseif (is_string($existingVal)) {
                        $saved = [$existingVal];
                    }
                }

                $files = $request->file($field);
                $filesArr = is_array($files) ? $files : [$files];
                foreach ($filesArr as $file) {
                    if ($file && $file->isValid()) {
                        $ext = strtolower((string) $file->getClientOriginalExtension());
                        $newName = \Illuminate\Support\Str::uuid() . '.' . $ext;
                        $file->move($uploadDir, $newName);
                        $saved[] = $newName;
                    }
                }

                if (!empty($saved)) {
                    $kyc->{$field} = json_encode(array_values(array_unique($saved)));
                }
            }
        }

        $kyc->save();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'KYC details uploaded successfully.',
            ]);
        }

        return back()->with('success', 'KYC details uploaded successfully.');
    }

    public function apiShow(): JsonResponse
    {
        $user = Auth::user();
        $settings = $this->profileSettings();
        $defaultAvatar = 'https://ui-avatars.com/api/?name=' . urlencode($user->name) . '&background=random&color=fff';
        $defaultLogo = url((env('PUBLIC_PATH') ? rtrim(env('PUBLIC_PATH'), '/') . '/' : '') . 'images/template/crmfavicon.png');
        $avatarUrl = !empty($user->avatar_path)
            ? route('users.image', $user) . '?v=' . optional($user->updated_at)->timestamp
            : $defaultAvatar;
        $companyLogoPath = $settings['company_logo_path'] ?? null;
        $companyLogoUrl = $companyLogoPath && Storage::disk('public')->exists($companyLogoPath)
            ? route('profile.company_logo.image') . '?v=' . Storage::disk('public')->lastModified($companyLogoPath)
            : $defaultLogo;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ],
                'settings' => $settings,
                'google_connected' => (bool) app('view')->shared('googleCalendarConnected', false),
                'avatar_url' => $avatarUrl,
                'company_logo_url' => $companyLogoUrl,
            ],
        ]);
    }

    public function disconnectGoogle()
    {
        abort_unless($this->googleConnectionEnabled(), 404);
        $googleService = $this->googleService();

        if (!$googleService) {
            return redirect()->route('profile.show')->with('error', 'Google Calendar integration is not available.');
        }

        if (!$googleService->isAuthenticated()) {
            return redirect()->route('profile.show')->with('error', 'Google Calendar is not connected.');
        }

        $success = $googleService->disconnect();

        return redirect()->route('profile.show')->with(
            $success ? 'success' : 'error',
            $success ? 'Google Calendar disconnected successfully.' : 'Failed to disconnect Google Calendar.'
        );
    }

    public function apiDisconnectGoogle(): JsonResponse
    {
        if (!$this->googleConnectionEnabled()) {
            return response()->json(['success' => false, 'message' => 'Google connection is disabled.'], 404);
        }
        $googleService = $this->googleService();

        if (!$googleService) {
            return response()->json(['success' => false, 'message' => 'Google Calendar integration is not available.'], 422);
        }

        if (!$googleService->isAuthenticated()) {
            return response()->json(['success' => false, 'message' => 'Google Calendar is not connected.'], 422);
        }

        $success = $googleService->disconnect();

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Google Calendar disconnected successfully.' : 'Failed to disconnect Google Calendar.',
        ], $success ? 200 : 500);
    }

    public function companyLogoImage()
    {
        $path = Setting::query()->where('key', 'company_logo_path')->value('value');
        $resolved = $this->resolvePublicFilePath($path);

        if (!$resolved) {
            abort(404);
        }

        return Response::file($resolved);
    }

    public function shortCompanyLogoImage()
    {
        $path = Setting::query()->where('key', 'short_company_logo_path')->value('value');
        $resolved = $this->resolvePublicFilePath($path);

        if (!$resolved) {
            abort(404);
        }

        return Response::file($resolved);
    }

    public function companySignatureImage()
    {
        $path = Setting::query()->where('key', 'company_signature_path')->value('value');
        $resolved = $this->resolvePublicFilePath($path);

        if (!$resolved) {
            abort(404);
        }

        return Response::file($resolved);
    }

    private function resolvePublicFilePath(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        $candidates = [
            Storage::disk('public')->path($path),
            storage_path('app/public/' . ltrim($path, '/\\')),
            storage_path('app/' . ltrim($path, '/\\')),
            public_path('storage/' . ltrim($path, '/\\')),
            public_path(ltrim($path, '/\\')),
        ];

        foreach ($candidates as $cand) {
            if ($cand && file_exists($cand) && !is_dir($cand)) {
                return $cand;
            }
        }

        return null;
    }

    private function googleConnectionEnabled(): bool
    {
        return Setting::query()->where('key', 'google_connection_enabled')->value('value') === '1';
    }

    public function update(Request $request)
    {
        $user = Auth::user();
        try {
            $this->persistProfileUpdate($request, $user);

            return redirect()->route('profile.show')->with('success', 'Profile updated successfully.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Profile update failed', [
                'user_id' => $user?->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error', 'Profile update failed. Please check inputs and try again.');
        }
    }

    public function apiUpdate(Request $request): JsonResponse
    {
        $user = Auth::user();
        $this->persistProfileUpdate($request, $user);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
        ]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
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
        $userUpdate = ['password' => Hash::make($request->password)];
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'pass_dec')) {
            $userUpdate['pass_dec'] = $request->password;
        }
        $user->update($userUpdate);

        if ($user->isAdmin() || $user->id === 1 || empty($user->parent_id)) {
            try {
                app(\App\Services\Tenancy\AdminErpSyncService::class)->syncClientPassword($user, $request->password);
            } catch (\Throwable $e) {
                Log::warning('Failed syncing password to admin_erp: ' . $e->getMessage());
            }
        }

        return back()->with('success', 'Password updated successfully.');
    }

    public function apiUpdatePassword(Request $request): JsonResponse
    {
        $request->validate([
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
        $userUpdate = ['password' => Hash::make($request->password)];
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'pass_dec')) {
            $userUpdate['pass_dec'] = $request->password;
        }
        $user->update($userUpdate);

        if ($user->isAdmin() || $user->id === 1 || empty($user->parent_id)) {
            try {
                app(\App\Services\Tenancy\AdminErpSyncService::class)->syncClientPassword($user, $request->password);
            } catch (\Throwable $e) {
                Log::warning('Failed syncing password to admin_erp: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }

    private function persistProfileUpdate(Request $request, $user): void
    {
        $imageFileRule = static function (string $attribute, $value, \Closure $fail): void {
            if (!$value || !$value->isValid()) {
                return;
            }

            $mimeType = (string) $value->getMimeType();

            if (!str_starts_with($mimeType, 'image/')) {
                $fail('Please upload a valid image file.');
            }
        };

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255', 'unique:users,username,' . $user->id],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'phone' => ['required', 'string', 'max:20'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_address' => ['required', 'string', 'max:255'],
            'company_tax_id' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'file', $imageFileRule, 'max:2048'],
            'company_logo_path' => ['nullable', 'file', $imageFileRule, 'max:2048'],
            'short_company_logo_path' => ['nullable', 'file', $imageFileRule, 'max:2048'],
        ], [
            'name.required' => 'Name is required!',
            'username.unique' => 'This username is already taken.',
            'phone.required' => 'Phone number is required!',
            'company_name.required' => 'Company name is required!',
            'company_address.required' => 'Address is required!',
            'avatar.max' => 'Profile image must not be larger than 2 MB.',
            'company_logo_path.max' => 'Company logo must not be larger than 2 MB.',
            'short_company_logo_path.max' => 'Short company logo must not be larger than 2 MB.',
        ]);

        if ($request->filled('username')) {
            $checkResp = app(\App\Http\Controllers\UsernameCheckController::class)->check(new Request([
                'username' => $request->input('username'),
                'ignore_user_id' => $user->id,
                'context' => 'profile',
            ]));
            $resData = $checkResp->getData(true);
            if (!empty($resData) && isset($resData['available']) && $resData['available'] === false) {
                throw ValidationException::withMessages([
                    'username' => [$resData['message'] ?? 'This username is already taken.'],
                ]);
            }
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $data['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }

        $userData = collect($data)->except(['company_name', 'company_address', 'company_tax_id', 'avatar', 'company_logo_path', 'short_company_logo_path'])->all();
        $oldEmail = $user->getOriginal('email');
        $user->update($userData);

        // Synchronize updated client details back to admin_erp database
        if ($user->isAdmin() || $user->id === 1 || empty($user->parent_id)) {
            try {
                app(\App\Services\Tenancy\AdminErpSyncService::class)->syncClientProfile($user, [
                    'company_name' => $request->input('company_name'),
                    'company_address' => $request->input('company_address'),
                ], $oldEmail);
            } catch (\Throwable $e) {
                Log::warning('Failed syncing client to admin_erp: ' . $e->getMessage());
            }
        }

        Setting::updateOrCreate(
            ['key' => 'company_name'],
            ['value' => $request->input('company_name'), 'group' => 'general', 'type' => 'string']
        );
        Setting::updateOrCreate(
            ['key' => 'company_address'],
            ['value' => $request->input('company_address'), 'group' => 'general', 'type' => 'string']
        );
        Setting::updateOrCreate(
            ['key' => 'company_tax_id'],
            ['value' => $request->input('company_tax_id'), 'group' => 'general', 'type' => 'string']
        );

        if ($request->hasFile('company_logo_path')) {
            $oldLogo = Setting::query()->where('key', 'company_logo_path')->value('value');
            if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
                Storage::disk('public')->delete($oldLogo);
            }

            $logoPath = $request->file('company_logo_path')->store('company', 'public');
            Setting::updateOrCreate(
                ['key' => 'company_logo_path'],
                ['value' => $logoPath, 'group' => 'general', 'type' => 'string']
            );
        }

        if ($request->hasFile('short_company_logo_path')) {
            $oldShortLogo = Setting::query()->where('key', 'short_company_logo_path')->value('value');
            if ($oldShortLogo && Storage::disk('public')->exists($oldShortLogo)) {
                Storage::disk('public')->delete($oldShortLogo);
            }

            $shortLogoPath = $request->file('short_company_logo_path')->store('company', 'public');
            Setting::updateOrCreate(
                ['key' => 'short_company_logo_path'],
                ['value' => $shortLogoPath, 'group' => 'general', 'type' => 'string']
            );
        }
    }

    private function profileSettings()
    {
        return Setting::query()->whereIn('key', [
            'company_name',
            'company_address',
            'company_tax_id',
            'company_logo_path',
            'short_company_logo_path',
        ])->pluck('value', 'key');
    }
}
