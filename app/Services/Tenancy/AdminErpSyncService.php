<?php

namespace App\Services\Tenancy;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdminErpSyncService
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
     * Resolve the client ID in admin_erp for the current tenant or user.
     */
    public function resolveClientId(?User $user = null, ?string $oldEmail = null): ?int
    {
        // 1. Direct tenant client_id from active tenant session
        if (function_exists('tenant') && tenant() && !empty(tenant('client_id'))) {
            return (int) tenant('client_id');
        }

        // 2. Direct tenant setup id
        if (function_exists('tenant') && tenant() && !empty(tenant('id'))) {
            return (int) tenant('id');
        }

        if (session()->has('tenant_id')) {
            return (int) session('tenant_id');
        }

        // 3. Known production host mapping
        $host = TenantManager::normalizeHost(request()->getHost());
        $knownHostMap = [
            'mediumslateblue-owl-603065.hostingersite.com' => 26,
            'crm.fableadtech.com'                          => 27,
            'travel-crm.fableadtech.com'                   => 10,
        ];
        if ($host && isset($knownHostMap[$host])) {
            return (int) $knownHostMap[$host];
        }

        // 4. Try resolving via direct DB if admin_erp connection is reachable
        try {
            $emailToCheck = $oldEmail ?: ($user?->email);
            if (!empty($emailToCheck)) {
                $client = DB::connection('admin_erp')
                    ->table('clients')
                    ->where('work_email', strtolower(trim($emailToCheck)))
                    ->first();
                if ($client && !empty($client->id)) {
                    return (int) $client->id;
                }
            }
        } catch (\Throwable) {
            // direct DB not reachable, proceed
        }

        return null;
    }

    /**
     * Synchronize updated user/client profile data back into admin_erp database.
     */
    public function syncClientProfile(User $user, array $extraData = [], ?string $oldEmail = null): bool
    {
        $clientId = $this->resolveClientId($user, $oldEmail);
        $host = TenantManager::normalizeHost(request()->getHost());
        $superAdminBase = $this->getSuperAdminBase();
        $secret = config('tenant.secret') ?: 'fab_erp_tenant_secret_key_8f7b2c9d1e4a6053';

        $payload = [
            'client_id'       => $clientId,
            'tenant_id'       => $clientId,
            'domain'          => $host,
            'current_email'   => $oldEmail ?: $user->email,
            'old_email'       => $oldEmail ?: $user->email,
            'name'            => $user->name,
            'client_name'     => $user->name,
            'username'        => $user->username,
            'email'           => $user->email,
            'work_email'      => $user->email,
            'phone'           => $user->phone,
            'mobile'          => $user->phone,
            'brand_name'      => $extraData['company_name'] ?? ($user->name ?? null),
            'company_name'    => $extraData['company_name'] ?? ($user->name ?? null),
            'location'        => $extraData['company_address'] ?? null,
            'company_address' => $extraData['company_address'] ?? null,
        ];

        $synced = false;

        // 1. Primary: Sync via Super Admin HTTP API
        try {
            $resp = Http::timeout(6)->acceptJson()
                ->withHeaders(['X-Tenant-Secret' => $secret])
                ->post("{$superAdminBase}/api/tenant/update-profile", $payload);

            if ($resp->successful() && $resp->json('success')) {
                $synced = true;
                Log::info("AdminErpSyncService: Successfully synced profile via tenant API for client #{$clientId}");
            } else {
                // Try fallback public client endpoint
                $fallbackResp = Http::timeout(6)->acceptJson()
                    ->post("{$superAdminBase}/api/client/update-profile", $payload);
                if ($fallbackResp->successful() && $fallbackResp->json('success')) {
                    $synced = true;
                    Log::info("AdminErpSyncService: Successfully synced profile via client update API for client #{$clientId}");
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AdminErpSyncService: HTTP profile sync failed: ' . $e->getMessage());
        }

        // 2. Secondary: If direct database connection admin_erp is available, update DB directly
        try {
            if ($clientId) {
                $clientsCols = Schema::connection('admin_erp')->getColumnListing('clients');
                $updateData = [];

                if (in_array('client_name', $clientsCols, true) && !empty($user->name)) {
                    $updateData['client_name'] = $user->name;
                }
                if (in_array('brand_name', $clientsCols, true)) {
                    $brand = $extraData['company_name'] ?? ($user->name ?? null);
                    if (!empty($brand)) {
                        $updateData['brand_name'] = $brand;
                    }
                }
                if (in_array('username', $clientsCols, true) && !empty($user->username)) {
                    $updateData['username'] = strtolower(trim($user->username));
                }
                if (in_array('work_email', $clientsCols, true) && !empty($user->email)) {
                    $updateData['work_email'] = strtolower(trim($user->email));
                }
                if (in_array('mobile', $clientsCols, true) && !empty($user->phone)) {
                    $updateData['mobile'] = $user->phone;
                }
                if (in_array('location', $clientsCols, true) && !empty($extraData['company_address'])) {
                    $updateData['location'] = $extraData['company_address'];
                }
                if (in_array('updated_at', $clientsCols, true)) {
                    $updateData['updated_at'] = now();
                }

                if (!empty($updateData)) {
                    DB::connection('admin_erp')
                        ->table('clients')
                        ->where('id', $clientId)
                        ->update($updateData);

                    if (Schema::connection('admin_erp')->hasTable('setups')) {
                        DB::connection('admin_erp')
                            ->table('setups')
                            ->where('client_id', $clientId)
                            ->update([
                                'client_name' => $user->name,
                                'updated_at'  => now(),
                            ]);
                    }
                    $synced = true;
                    Log::info("AdminErpSyncService: Direct DB synced client #{$clientId}");
                }
            }
        } catch (\Throwable) {
            // DB connection not available in remote environment
        }

        // 3. Update current session tenant cache
        if (session()->has('tenant')) {
            $t = session('tenant');
            if (is_array($t)) {
                $t['client_name'] = $user->name;
                $t['username'] = $user->username;
                $t['work_email'] = $user->email;
                $t['mobile'] = $user->phone;
                session(['tenant' => $t]);
                app()->instance('tenant', $t);
            }
        }

        return $synced;
    }

    /**
     * Synchronize updated password back into admin_erp database.
     */
    public function syncClientPassword(User $user, string $password): bool
    {
        $clientId = $this->resolveClientId($user);
        $host = TenantManager::normalizeHost(request()->getHost());
        $superAdminBase = $this->getSuperAdminBase();
        $secret = config('tenant.secret') ?: 'fab_erp_tenant_secret_key_8f7b2c9d1e4a6053';

        $payload = [
            'client_id'     => $clientId,
            'tenant_id'     => $clientId,
            'domain'        => $host,
            'current_email' => $user->email,
            'email'         => $user->email,
            'username'      => $user->username,
            'password'      => $password,
        ];

        $synced = false;

        // 1. Primary: Sync via Super Admin HTTP API
        try {
            $resp = Http::timeout(6)->acceptJson()
                ->withHeaders(['X-Tenant-Secret' => $secret])
                ->post("{$superAdminBase}/api/tenant/update-profile", $payload);

            if ($resp->successful() && $resp->json('success')) {
                $synced = true;
                Log::info("AdminErpSyncService: Successfully synced plain text password via tenant API for client #{$clientId}");
            } else {
                $tenantPassResp = Http::timeout(6)->acceptJson()
                    ->withHeaders(['X-Tenant-Secret' => $secret])
                    ->post("{$superAdminBase}/api/tenant/update-client-password", $payload);
                if ($tenantPassResp->successful() && $tenantPassResp->json('success')) {
                    $synced = true;
                    Log::info("AdminErpSyncService: Successfully synced plain text password via tenant update-client-password API for client #{$clientId}");
                } else {
                    $fallbackResp = Http::timeout(6)->acceptJson()
                        ->post("{$superAdminBase}/api/client/update-password", $payload);
                    if ($fallbackResp->successful() && $fallbackResp->json('success')) {
                        $synced = true;
                        Log::info("AdminErpSyncService: Successfully synced plain text password via client update-password API for client #{$clientId}");
                    } else {
                        $fallbackProfileResp = Http::timeout(6)->acceptJson()
                            ->post("{$superAdminBase}/api/client/update-profile", $payload);
                        if ($fallbackProfileResp->successful() && $fallbackProfileResp->json('success')) {
                            $synced = true;
                            Log::info("AdminErpSyncService: Successfully synced plain text password via client update-profile API for client #{$clientId}");
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AdminErpSyncService: HTTP password sync failed: ' . $e->getMessage());
        }

        // 2. Secondary: If direct database connection admin_erp is available, update DB directly
        try {
            if (config('database.connections.admin_erp')) {
                $clientsCols = Schema::connection('admin_erp')->getColumnListing('clients');
                if (in_array('password', $clientsCols, true)) {
                    $updatedRows = 0;
                    if ($clientId) {
                        $updatedRows = DB::connection('admin_erp')
                            ->table('clients')
                            ->where('id', $clientId)
                            ->update([
                                'password'   => (string) $password,
                                'updated_at' => now(),
                            ]);
                    }

                    if ($updatedRows === 0 && !empty($user->email)) {
                        $updatedRows = DB::connection('admin_erp')
                            ->table('clients')
                            ->whereRaw('LOWER(TRIM(work_email)) = ?', [strtolower(trim($user->email))])
                            ->update([
                                'password'   => (string) $password,
                                'updated_at' => now(),
                            ]);
                    }

                    if ($updatedRows === 0 && !empty($user->username) && in_array('username', $clientsCols, true)) {
                        $updatedRows = DB::connection('admin_erp')
                            ->table('clients')
                            ->whereRaw('LOWER(TRIM(username)) = ?', [strtolower(trim($user->username))])
                            ->update([
                                'password'   => (string) $password,
                                'updated_at' => now(),
                            ]);
                    }

                    if ($updatedRows > 0) {
                        $synced = true;
                        Log::info("AdminErpSyncService: Direct DB synced plain text password for client #{$clientId}");
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AdminErpSyncService: Direct DB password sync failed: ' . $e->getMessage());
        }

        return $synced;
    }
}
