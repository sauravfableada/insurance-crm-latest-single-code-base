<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UsernameCheckController extends Controller
{
    /**
     * Get the Super Admin base API URL with robust fallback to production URL.
     */
    protected function getSuperAdminBase(): string
    {
        $apiUrl = config('tenant.api_url');
        $host = request()->getHost();
        $isLiveDomain = !in_array($host, ['localhost', '127.0.0.1'], true);

        if ($isLiveDomain && (empty($apiUrl) || str_contains($apiUrl, 'localhost') || str_contains($apiUrl, '127.0.0.1'))) {
            return 'https://admin-products.fableadtech.com/services/public';
        }

        if (empty($apiUrl) || !str_contains($apiUrl, 'http')) {
            $apiUrl = 'https://admin-products.fableadtech.com/services/public/api/tenant';
        }

        if (str_contains($apiUrl, 'admin-products.fableadtech.com') && !str_contains($apiUrl, '/services/public')) {
            $apiUrl = str_replace('admin-products.fableadtech.com', 'admin-products.fableadtech.com/services/public', $apiUrl);
        }

        $base = preg_replace('#/api/tenant/?$#i', '', $apiUrl);
        $base = rtrim($base, '/');

        if (str_contains($base, 'admin-products.fableadtech.com') && !str_contains($base, '/services/public')) {
            $base .= '/services/public';
        }

        return $base ?: 'https://admin-products.fableadtech.com/services/public';
    }

    /**
     * Check if a username is available both in local CRM and in Super Admin (admin_erp).
     */
    public function check(Request $request): JsonResponse
    {
        $raw = $request->input('username') ?? $request->input('user_name');
        $username = strtolower(trim((string) $raw));

        if (empty($username)) {
            return response()->json([
                'success'   => false,
                'available' => false,
                'message'   => 'Username is required.',
            ], 422);
        }

        if (strlen($username) < 3) {
            return response()->json([
                'success'   => true,
                'available' => false,
                'message'   => 'Username must be at least 3 characters long.',
            ]);
        }

        $ignoreUserId = $request->input('ignore_user_id') ?? $request->input('user_id');
        $ignoreCustomerId = $request->input('ignore_customer_id') ?? $request->input('customer_id');
        $context = $request->input('context'); // 'profile', 'customer', 'team_member', 'user'

        // 1. Check in local users table (by username or email)
        if (Schema::hasTable('users')) {
            $userQuery = User::where(function ($q) use ($username) {
                if (Schema::hasColumn('users', 'username')) {
                    $q->whereRaw('LOWER(TRIM(username)) = ?', [$username]);
                }
                if (Schema::hasColumn('users', 'email')) {
                    $q->orWhereRaw('LOWER(TRIM(email)) = ?', [$username]);
                }
            });

            if (!empty($ignoreUserId)) {
                $userQuery->where('id', '!=', $ignoreUserId);
            }

            if ($userQuery->exists()) {
                return response()->json([
                    'success'   => true,
                    'available' => false,
                    'source'    => 'local_user',
                    'message'   => 'Username is already taken in this CRM.',
                ]);
            }
        }

        // 2. Check in local customers table
        if (class_exists(Customer::class) && Schema::hasTable('customers')) {
            $custQuery = Customer::where(function ($q) use ($username) {
                if (Schema::hasColumn('customers', 'username')) {
                    $q->whereRaw('LOWER(TRIM(username)) = ?', [$username]);
                }
                if (Schema::hasColumn('customers', 'email')) {
                    $q->orWhereRaw('LOWER(TRIM(email)) = ?', [$username]);
                }
            });

            if (!empty($ignoreCustomerId)) {
                $custQuery->where('id', '!=', $ignoreCustomerId);
            }

            if ($custQuery->exists()) {
                return response()->json([
                    'success'   => true,
                    'available' => false,
                    'source'    => 'local_customer',
                    'message'   => 'Username is already taken in this CRM.',
                ]);
            }
        }

        // 3. Resolve Current Tenant & Client ID
        $superAdminBase = $this->getSuperAdminBase();
        $secret = config('tenant.secret') ?: 'fab_erp_tenant_secret_key_8f7b2c9d1e4a6053';

        $tenant = function_exists('tenant') ? tenant() : session('tenant');
        $currentClientId = is_array($tenant) ? ($tenant['client_id'] ?? ($tenant['id'] ?? null)) : null;
        $currentHost = strtolower(trim((string) request()->getHost()));

        // 4. Check against Super Admin active setups via public endpoint (api/setup/search)
        // Completely public, live, and contains all tenant client usernames & emails.
        $bases = array_unique(array_filter([
            $superAdminBase,
            'https://admin-products.fableadtech.com/services/public',
        ]));

        foreach ($bases as $adminBase) {
            try {
                $cacheKey = 'super_admin_setups_search_' . md5($adminBase);
                $setups = Cache::remember($cacheKey, 20, function () use ($adminBase) {
                    $resp = Http::timeout(4)->acceptJson()->get("{$adminBase}/api/setup/search");
                    if ($resp->successful() && is_array($resp->json('data'))) {
                        return $resp->json('data');
                    }
                    return null;
                });

                if (!empty($setups) && is_array($setups)) {
                    foreach ($setups as $s) {
                        $client = $s['client'] ?? [];
                        $cId = $client['id'] ?? ($s['client_id'] ?? null);
                        $cDomain = strtolower(trim((string)($s['domain'] ?? '')));
                        $cUsername = strtolower(trim((string)($client['username'] ?? '')));
                        $cEmail = strtolower(trim((string)($client['work_email'] ?? '')));
                        $cName = $client['client_name'] ?? 'Super Admin Client';

                        $isCurrentTenant = false;
                        if (!empty($currentClientId) && !empty($cId) && (string)$cId === (string)$currentClientId) {
                            $isCurrentTenant = true;
                        } elseif (!empty($currentHost) && !empty($cDomain) && !in_array($currentHost, ['localhost', '127.0.0.1'], true) && ($currentHost === $cDomain || str_contains($cDomain, $currentHost))) {
                            $isCurrentTenant = true;
                        }

                        if ($isCurrentTenant && empty($currentClientId) && !empty($cId)) {
                            $currentClientId = $cId;
                        }

                        // If it belongs to another tenant/client in Super Admin
                        if (!$isCurrentTenant) {
                            if (($cUsername !== '' && $cUsername === $username) || ($cEmail !== '' && $cEmail === $username)) {
                                return response()->json([
                                    'success'   => true,
                                    'available' => false,
                                    'source'    => 'admin_erp',
                                    'message'   => "Username is already taken in Super Admin ({$cName}).",
                                ]);
                            }
                        } else {
                            // If it belongs to current tenant
                            if ($context === 'profile') {
                                if (($cUsername !== '' && $cUsername === $username) || ($cEmail !== '' && $cEmail === $username)) {
                                    return response()->json([
                                        'success'   => true,
                                        'available' => true,
                                        'message'   => 'Current username (available).',
                                    ]);
                                }
                            } else {
                                if (($cUsername !== '' && $cUsername === $username) || ($cEmail !== '' && $cEmail === $username)) {
                                    return response()->json([
                                        'success'   => true,
                                        'available' => false,
                                        'source'    => 'admin_erp',
                                        'message'   => "Username is assigned to this tenant's primary admin in Super Admin.",
                                    ]);
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Super Admin setup/search check failed: ' . $e->getMessage());
            }

            // 5. Check against Super Admin check-username endpoint (full database check)
            try {
                $resp = Http::timeout(3)->acceptJson()->post("{$adminBase}/api/check-username", array_filter([
                    'username'  => $username,
                    'client_id' => $currentClientId,
                ]));
                if ($resp->successful()) {
                    $data = $resp->json();
                    if (isset($data['available']) && $data['available'] === false) {
                        return response()->json([
                            'success'   => true,
                            'available' => false,
                            'source'    => 'admin_erp',
                            'message'   => $data['message'] ?? 'Username is already taken in Central Portal.',
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                try {
                    $respSecret = Http::timeout(2)->acceptJson()->withHeaders([
                        'X-Tenant-Secret' => $secret,
                    ])->post("{$adminBase}/api/tenant/check-username", array_filter([
                        'username'  => $username,
                        'client_id' => $currentClientId,
                    ]));
                    if ($respSecret->successful()) {
                        $dataSecret = $respSecret->json();
                        if (isset($dataSecret['available']) && $dataSecret['available'] === false) {
                            return response()->json([
                                'success'   => true,
                                'available' => false,
                                'source'    => 'admin_erp',
                                'message'   => $dataSecret['message'] ?? 'Username is already taken in Central Portal.',
                            ]);
                        }
                    }
                } catch (\Throwable $ex) {
                    Log::warning('Super Admin check-username endpoints unavailable: ' . $ex->getMessage());
                }
            }
        }

        return response()->json([
            'success'   => true,
            'available' => true,
            'message'   => 'Username is available.',
        ]);
    }
}


