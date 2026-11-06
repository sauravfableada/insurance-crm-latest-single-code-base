<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        // 3. Check in Super Admin (admin_erp)
        $superAdminBase = $this->getSuperAdminBase();
        $secret = config('tenant.secret') ?: 'fab_erp_tenant_secret_key_8f7b2c9d1e4a6053';

        $clientId = null;
        if ($context === 'profile' || auth()->user()?->isAdmin() || auth()->id() === 1) {
            $tenant = function_exists('tenant') ? tenant() : session('tenant');
            $clientId = is_array($tenant) ? ($tenant['client_id'] ?? ($tenant['id'] ?? null)) : null;
        }

        if ($superAdminBase) {
            try {
                $resp = Http::timeout(3)->acceptJson()->withHeaders([
                    'X-Tenant-Secret' => $secret,
                ])->post("{$superAdminBase}/api/tenant/check-username", array_filter([
                    'username'  => $username,
                    'client_id' => $clientId,
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
                // Fallback to public check-username endpoint if tenant-secret call fails
                try {
                    $respPub = Http::timeout(2)->acceptJson()->post("{$superAdminBase}/api/check-username", array_filter([
                        'username'  => $username,
                        'client_id' => $clientId,
                    ]));
                    if ($respPub->successful()) {
                        $dataPub = $respPub->json();
                        if (isset($dataPub['available']) && $dataPub['available'] === false) {
                            return response()->json([
                                'success'   => true,
                                'available' => false,
                                'source'    => 'admin_erp',
                                'message'   => $dataPub['message'] ?? 'Username is already taken in Central Portal.',
                            ]);
                        }
                    }
                } catch (\Throwable $ex) {
                    Log::warning('Super Admin check-username endpoint unavailable: ' . $ex->getMessage());
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

