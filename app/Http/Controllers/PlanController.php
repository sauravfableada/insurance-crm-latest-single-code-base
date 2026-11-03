<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PlanController extends Controller
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

        // Auto-correct if pointing to admin-products.fableadtech.com without /services/public
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
     * Dynamically resolve tenant context from TenantManager, session, or Super Admin API.
     */
    public function resolveTenantContext(bool $forceFresh = false): ?array
    {
        // 1. Check existing tenant context from TenantManager (if not forced fresh)
        if (!$forceFresh && !request()->has('refresh_tenant')) {
            $tenant = function_exists('tenant') ? tenant() : null;
            if (!empty($tenant) && is_array($tenant) && !empty($tenant['client_id'] ?? $tenant['id'])) {
                if (!empty($tenant['modules']) && count($tenant['modules']) > 8) {
                    $syncedAt = session('tenant_synced_at', 0);
                    if ($syncedAt && (time() - $syncedAt) < 300) {
                        return $tenant;
                    }
                }
            }

            // 2. Check session tenant (if not forced fresh)
            if (session()->has('tenant')) {
                $sessionTenant = session('tenant');
                if (is_array($sessionTenant) && !empty($sessionTenant['client_id'] ?? $sessionTenant['id'])) {
                    if (!empty($sessionTenant['modules']) && count($sessionTenant['modules']) > 8) {
                        $syncedAt = session('tenant_synced_at', 0);
                        if ($syncedAt && (time() - $syncedAt) < 300) {
                            if (app()->bound(\App\Services\Tenancy\TenantManager::class)) {
                                app()->instance('tenant', $sessionTenant);
                            }
                            return $sessionTenant;
                        }
                    }
                }
            }
        }

        // 3. Fetch from Super Admin dynamically by host or authenticated user email
        $superAdminBase = $this->getSuperAdminBase();
        $secret = config('tenant.secret') ?: 'fab_erp_tenant_secret_key_8f7b2c9d1e4a6053';
        $host = \App\Services\Tenancy\TenantManager::normalizeHost(request()->getHost());

        $resolved = null;

        // Try direct public endpoint for host domain first (bypasses secret header)
        $resolved = $this->resolveFromPublicClientData($superAdminBase, $host);

        // Try by host domain with /resolve if public endpoint didn't resolve
        if (!$resolved && $host && !in_array($host, ['localhost', '127.0.0.1'], true)) {
            try {
                $resp = Http::timeout(6)->acceptJson()->withHeaders(['X-Tenant-Secret' => $secret])
                    ->post("{$superAdminBase}/api/tenant/resolve", [
                        'domain'       => $host,
                        'product_code' => config('tenant.product_code', 'CRM'),
                    ]);
                if ($resp->successful() && $resp->json('success') && !empty($resp->json('tenant'))) {
                    $resolved = $resp->json('tenant');
                }
            } catch (\Throwable $e) {
                Log::warning('Dynamic tenant resolution by host failed: ' . $e->getMessage());
            }
        }

        // Try by user email / login if domain lookup failed or was local
        if (!$resolved && auth()->check()) {
            $user = auth()->user();
            $login = $user->email ?: ($user->username ?? '');
            if ($login) {
                try {
                    $resp = Http::timeout(6)->acceptJson()->withHeaders(['X-Tenant-Secret' => $secret])
                        ->post("{$superAdminBase}/api/tenant/resolve-user", [
                            'login'        => $login,
                            'email'        => $user->email,
                            'username'     => $user->username ?? null,
                            'product_code' => config('tenant.product_code', 'CRM'),
                        ]);
                    if ($resp->successful() && $resp->json('success') && !empty($resp->json('tenant'))) {
                        $resolved = $resp->json('tenant');
                    }
                } catch (\Throwable $e) {
                    Log::warning('Dynamic tenant resolution by user failed: ' . $e->getMessage());
                }
            }
        }

        if ($resolved) {
            session([
                'tenant'           => $resolved,
                'tenant_id'        => $resolved['id'] ?? null,
                'tenant_synced_at' => time(),
            ]);
            app()->instance('tenant', $resolved);

            // Immediately synchronize local database active plan with Super Admin tenant configuration
            $this->syncLocalPlanWithTenantData($resolved);

            return $resolved;
        }

        return $tenant ?? ($sessionTenant ?? null);
    }

    /**
     * Fallback resolution using Super Admin public client/data endpoint
     * (unprotected, fetches real assigned modules and limits without requiring X-Tenant-Secret).
     */
    protected function resolveFromPublicClientData(string $superAdminBase, ?string $host): ?array
    {
        // 1. Try with authenticated user's email if available
        if (auth()->check()) {
            $user = auth()->user();
            $email = $user->email ?: null;
            if ($email) {
                try {
                    $resp = Http::timeout(5)->acceptJson()->post("{$superAdminBase}/api/client/data", [
                        'email' => $email,
                    ]);
                    if ($resp->successful() && $resp->json('success') && !empty($resp->json('data.client'))) {
                        return $this->formatTenantFromClientData($resp->json('data'));
                    }
                } catch (\Throwable $e) {
                    Log::warning('client/data by user email failed: ' . $e->getMessage());
                }
            }
        }

        // 2. Direct mapping for known domains
        $knownHostMap = [
            'mediumslateblue-owl-603065.hostingersite.com' => 26,
            'crm.fableadtech.com'                          => 27,
            'travel-crm.fableadtech.com'                   => 10,
        ];

        $clientId = $host ? ($knownHostMap[$host] ?? null) : null;
        if ($clientId) {
            try {
                $resp = Http::timeout(5)->acceptJson()->get("{$superAdminBase}/api/client/data/{$clientId}");
                if ($resp->successful() && $resp->json('success') && !empty($resp->json('data.client'))) {
                    return $this->formatTenantFromClientData($resp->json('data'));
                }
            } catch (\Throwable $e) {
                Log::warning("client/data by ID {$clientId} failed: " . $e->getMessage());
            }
        }

        // 3. Scan recent client setup IDs for matching domain
        if ($host && !in_array($host, ['localhost', '127.0.0.1'], true)) {
            for ($cid = 20; $cid <= 35; $cid++) {
                try {
                    $resp = Http::timeout(3)->acceptJson()->get("{$superAdminBase}/api/client/data/{$cid}");
                    if ($resp->successful() && $resp->json('success') && !empty($resp->json('data.setup.domain'))) {
                        $setupDomain = $resp->json('data.setup.domain');
                        if (strcasecmp(trim($setupDomain), trim($host)) === 0) {
                            return $this->formatTenantFromClientData($resp->json('data'));
                        }
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Format raw payload from Super Admin client/data into standardized tenant array.
     */
    protected function formatTenantFromClientData(array $data): array
    {
        $c = $data['client'] ?? [];
        $setup = $data['setup'] ?? [];
        $mem = $data['membership'] ?? [];
        $pm = $data['project_modules'] ?? [];
        $sm = $data['sub_modules'] ?? [];

        $mods = [];
        if (!empty($setup['assigned_modules']) && is_array($setup['assigned_modules'])) {
            $mods = array_values($setup['assigned_modules']);
        } elseif (!empty($pm) && is_array($pm)) {
            $mods = array_values(array_column($pm, 'name'));
        }

        $subMods = [];
        if (!empty($setup['assigned_sub_modules']) && is_array($setup['assigned_sub_modules'])) {
            $subMods = array_values($setup['assigned_sub_modules']);
        } elseif (!empty($sm) && is_array($sm)) {
            $subMods = array_values(array_column($sm, 'name'));
        }

        $allModules = array_values(array_unique(array_filter(array_merge($mods, $subMods))));

        return [
            'id'            => $setup['id'] ?? ($c['id'] ?? null),
            'client_id'     => $c['id'] ?? null,
            'client_name'   => $c['client_name'] ?? null,
            'brand_name'    => $c['brand_name'] ?? null,
            'domain'        => $setup['domain'] ?? null,
            'product_code'  => $data['product']['code'] ?? 'CRM',
            'project_code'  => $data['project']['code'] ?? 'CRM',
            'status'        => $setup['status'] ?? ($c['status'] ?? 'Active'),
            'start_date'    => $c['calculated_start_date'] ?? ($c['start_date'] ?? null),
            'expiry_date'   => $c['calculated_end_date'] ?? ($c['expiry_date'] ?? null),
            'plan_name'     => $setup['plan_name'] ?? ($mem['plan_name'] ?? ($c['brand_name'] ?? 'Insurance-CRM')),
            'billing_cycle' => $setup['billing_cycle'] ?? ($c['billing_title'] ?? 'monthly'),
            'amount'        => $c['total_amount'] ?? ($setup['amount'] ?? 50),
            'renewal_amount'=> $c['custom_renewal_amount'] ?? ($setup['renewal_amount'] ?? 49),
            'max_user'      => $c['max_user'] ?? 50,
            'max_branch'    => $c['max_branch'] ?? 1,
            'modules'       => $allModules,
            'sub_modules'   => $subMods,
            'email'         => $c['work_email'] ?? null,
        ];
    }

    /**
     * Synchronize local database active Plan with tenant data from Super Admin.
     */
    public function syncLocalPlanWithTenantData(?array $tenant): ?Plan
    {
        if (empty($tenant)) {
            return null;
        }

        try {
            $user = auth()->check() ? auth()->user() : null;
            $plan = $user ? $user->getActivePlan() : null;
            if (!$plan) {
                $plan = Plan::where('is_active', 1)->latest('id')->first();
            }
            if (!$plan) {
                $plan = Plan::latest('id')->first();
            }

            $tenantPlanName = $tenant['plan_name'] ?? null;
            $tenantAmount = isset($tenant['amount']) ? (float)(is_array($tenant['amount']) ? ($tenant['amount'][0] ?? 0) : $tenant['amount']) : 0;
            $tenantRenewal = isset($tenant['renewal_amount']) ? (float)(is_array($tenant['renewal_amount']) ? ($tenant['renewal_amount'][0] ?? 0) : $tenant['renewal_amount']) : 0;
            if ($tenantRenewal <= 0 && $tenantAmount > 0) {
                $tenantRenewal = $tenantAmount;
            }

            $tenantModules = !empty($tenant['modules']) && is_array($tenant['modules']) ? array_values($tenant['modules']) : [];
            $clientName = $tenant['client_name'] ?? ($tenant['brand_name'] ?? ($user?->name ?? 'Insurance-CRM'));

            $updateData = [];

            if (!empty($tenantModules)) {
                $updateData['features'] = $tenantModules;
            }

            if (!empty($tenantPlanName)) {
                $updateData['name'] = $tenantPlanName;
            }

            if ($tenantAmount > 0) {
                $updateData['price'] = $tenantAmount;
                $updateData['total_amount'] = $tenantAmount;
                $updateData['total_price'] = $tenantAmount;
            }

            if ($tenantRenewal > 0) {
                $updateData['renewal_price'] = $tenantRenewal;
            }

            if (!empty($tenant['expiry_date'])) {
                $updateData['end_date'] = Carbon::parse($tenant['expiry_date'])->format('Y-m-d');
            }

            if (!empty($tenant['start_date'])) {
                $updateData['start_date'] = Carbon::parse($tenant['start_date'])->format('Y-m-d');
            }

            if (!empty($tenant['billing_cycle'])) {
                $updateData['duration'] = str_contains(strtolower($tenant['billing_cycle']), 'year') ? 'year' : 'month';
            }

            if (!empty($tenant['max_user'])) {
                $updateData['user_limit'] = (int)$tenant['max_user'];
            }

            if (!empty($tenant['max_branch'])) {
                $updateData['branch_limit'] = (int)$tenant['max_branch'];
            }

            $updateData['subtitle'] = 'Purchased By ' . $clientName;
            $updateData['is_active'] = true;

            if ($plan) {
                $plan->update($updateData);
                $plan->refresh();
            } else {
                $updateData['name'] = $tenantPlanName ?: 'insurance-crm';
                $updateData['price'] = $tenantAmount > 0 ? $tenantAmount : 50.00;
                $updateData['renewal_price'] = $tenantRenewal > 0 ? $tenantRenewal : 49.00;
                $updateData['total_amount'] = $updateData['price'];
                $updateData['total_price'] = $updateData['price'];
                $updateData['duration'] = 'month';
                $updateData['start_date'] = now()->format('Y-m-d');
                $updateData['end_date'] = now()->addMonth()->format('Y-m-d');
                $updateData['user_limit'] = $tenant['max_user'] ?? 50;
                $updateData['branch_limit'] = $tenant['max_branch'] ?? 1;

                $plan = Plan::create($updateData);
            }

            if ($user && empty($user->plan_id)) {
                $user->update(['plan_id' => $plan->id]);
            }

            return $plan;
        } catch (\Throwable $e) {
            Log::error('Failed to sync local plan with tenant data: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * AJAX/Webhook endpoint to immediately sync modules and limits from Super Admin.
     */
    public function syncModulesWithSuperAdmin(Request $request)
    {
        try {
            $inputModules = $request->input('assigned_modules') ?? $request->input('modules');
            $inputSubModules = $request->input('assigned_sub_modules') ?? $request->input('sub_modules');

            if (!empty($inputModules) && is_array($inputModules)) {
                $allMods = array_values(array_unique(array_filter(array_merge(
                    $inputModules,
                    is_array($inputSubModules) ? $inputSubModules : []
                ))));

                $tenant = [
                    'id'            => $request->input('id') ?? $request->input('client_id'),
                    'client_id'     => $request->input('client_id'),
                    'plan_name'     => $request->input('plan_name') ?: 'Insurance-CRM',
                    'amount'        => $request->input('amount'),
                    'renewal_amount'=> $request->input('renewal_amount'),
                    'max_user'      => $request->input('max_user'),
                    'max_branch'    => $request->input('max_branch'),
                    'billing_cycle' => $request->input('billing_cycle'),
                    'modules'       => $allMods,
                    'sub_modules'   => is_array($inputSubModules) ? $inputSubModules : [],
                ];

                if ($request->hasSession()) {
                    session([
                        'tenant' => $tenant,
                        'tenant_synced_at' => time(),
                    ]);
                }
                app()->instance('tenant', $tenant);

                $plan = $this->syncLocalPlanWithTenantData($tenant);

                return response()->json([
                    'success' => true,
                    'message' => 'Modules updated directly from webhook!',
                    'modules_count' => count($allMods),
                    'modules' => $allMods,
                    'plan_name' => $plan?->name ?? 'Active Plan',
                ]);
            }

            // Force bypass session/cache to fetch live configuration from Super Admin
            $tenant = $this->resolveTenantContext(true);

            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to connect to Super Admin or locate tenant configuration.',
                ], 404);
            }

            $plan = $this->syncLocalPlanWithTenantData($tenant);
            $features = $plan?->features ?? ($tenant['modules'] ?? []);

            return response()->json([
                'success' => true,
                'message' => 'Successfully synchronized modules and plan configuration from Super Admin!',
                'modules_count' => count($features),
                'modules' => $features,
                'plan_name' => $plan?->name ?? ($tenant['plan_name'] ?? 'Active Plan'),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error synchronizing: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display current active plan details, limits, and billing history.
     */
    public function myplan()
    {
        $user = auth()->user();
        // Force fresh pull from Super Admin on /my-plan to guarantee up-to-date modules
        $tenant = $this->resolveTenantContext(true);
        $clientId = $tenant['client_id'] ?? ($tenant['id'] ?? null);

        $superAdminBase = $this->getSuperAdminBase();

        // 1. Get or initialize local active Plan
        $plan = $user ? $user->getActivePlan() : null;
        if (!$plan) {
            $plan = Plan::where('is_active', 1)->latest('id')->first();
        }

        // Sync with local database Plan
        if ($tenant) {
            $plan = $this->syncLocalPlanWithTenantData($tenant) ?: $plan;
        }

        $tenantPlanName = $tenant['plan_name'] ?? null;
        $tenantAmount = isset($tenant['amount']) ? (float)(is_array($tenant['amount']) ? ($tenant['amount'][0] ?? 0) : $tenant['amount']) : 0;
        $tenantRenewal = isset($tenant['renewal_amount']) ? (float)(is_array($tenant['renewal_amount']) ? ($tenant['renewal_amount'][0] ?? 0) : $tenant['renewal_amount']) : 0;

        if ($tenantRenewal <= 0 && $tenantAmount > 0) {
            $tenantRenewal = $tenantAmount;
        }

        $isLegacyPlan = $plan && ($plan->name === 'Starter Plan' || (float)$plan->total_amount === 1000.00 || (float)$plan->price === 1000.00);

        if (!$plan && $tenant) {
            $initialAmount = $tenantAmount > 0 ? $tenantAmount : 50.00;
            $initialRenewal = $tenantRenewal > 0 ? $tenantRenewal : 49.00;
            $initialName = $tenantPlanName ?: 'insurance-crm';

            $plan = Plan::create([
                'name'          => $initialName,
                'price'         => $initialAmount,
                'renewal_price' => $initialRenewal,
                'total_amount'  => $initialAmount,
                'total_price'   => $initialAmount,
                'duration'      => (str_contains(strtolower($tenant['billing_cycle'] ?? ''), 'month')) ? 'month' : 'month',
                'start_date'    => !empty($tenant['start_date']) ? Carbon::parse($tenant['start_date'])->format('Y-m-d') : now()->format('Y-m-d'),
                'end_date'      => !empty($tenant['expiry_date']) ? Carbon::parse($tenant['expiry_date'])->format('Y-m-d') : now()->addMonth()->format('Y-m-d'),
                'subtitle'      => 'Purchased By ' . ($tenant['client_name'] ?? ($tenant['brand_name'] ?? ($user->name ?? 'Insurance-CRM'))),
                'user_limit'    => $tenant['max_user'] ?? 50,
                'branch_limit'  => $tenant['max_branch'] ?? 1,
                'is_active'     => true,
                'features'      => !empty($tenant['modules']) && is_array($tenant['modules']) ? array_values($tenant['modules']) : [
                    'Dashboard', 'Client Manage', 'Sales CRM', 'Policy Center', 'Commissions', 'Withdrawal Requests', 'Operations', 'Sub Agent'
                ],
            ]);

            $user->update(['plan_id' => $plan->id]);
        } elseif (!$plan || $isLegacyPlan) {
            // Local fallback or replace legacy starter placeholder with accurate tenant values
            $defaultName = $tenantPlanName ?: 'insurance-crm';
            $defaultAmount = $tenantAmount > 0 ? $tenantAmount : 50.00;
            $defaultRenewal = $tenantRenewal > 0 ? $tenantRenewal : 49.00;

            if ($plan) {
                $plan->update([
                    'name'          => $defaultName,
                    'price'         => $defaultAmount,
                    'renewal_price' => $defaultRenewal,
                    'total_amount'  => $defaultAmount,
                    'total_price'   => $defaultAmount,
                    'duration'      => (str_contains(strtolower($tenant['billing_cycle'] ?? ''), 'month')) ? 'month' : 'month',
                    'subtitle'      => 'Purchased By ' . ($tenant['client_name'] ?? ($tenant['brand_name'] ?? ($user->name ?? 'Insurance-CRM'))),
                    'user_limit'    => $tenant['max_user'] ?? 50,
                    'branch_limit'  => $tenant['max_branch'] ?? 1,
                ]);
            } else {
                $plan = Plan::create([
                    'name'          => $defaultName,
                    'price'         => $defaultAmount,
                    'renewal_price' => $defaultRenewal,
                    'total_amount'  => $defaultAmount,
                    'total_price'   => $defaultAmount,
                    'duration'      => 'month',
                    'start_date'    => now()->format('Y-m-d'),
                    'end_date'      => now()->addMonth()->format('Y-m-d'),
                    'subtitle'      => 'Purchased By ' . ($user->name ?? 'Insurance-CRM'),
                    'user_limit'    => 50,
                    'branch_limit'  => 1,
                    'is_active'     => true,
                    'features'      => [
                        'Dashboard', 'Client Manage', 'Sales CRM', 'Policy Center', 'Commissions', 'Withdrawal Requests', 'Operations', 'Sub Agent'
                    ],
                ]);
                $user->update(['plan_id' => $plan->id]);
            }
        }

        // 2. Synchronize all plan details dynamically with tenant payload from Super Admin
        if ($tenant && $plan) {
            $needsUpdate = false;
            $updateData = [];

            // Sync Plan Name
            if (!empty($tenantPlanName) && $plan->name !== $tenantPlanName) {
                $updateData['name'] = $tenantPlanName;
                $needsUpdate = true;
            }

            // Sync Total Amount & Price
            if ($tenantAmount > 0 && (float)$plan->total_amount !== $tenantAmount) {
                $updateData['price'] = $tenantAmount;
                $updateData['total_amount'] = $tenantAmount;
                $updateData['total_price'] = $tenantAmount;
                $needsUpdate = true;
            }

            // Sync Renewal Price
            if ($tenantRenewal > 0 && (float)$plan->renewal_price !== $tenantRenewal) {
                $updateData['renewal_price'] = $tenantRenewal;
                $needsUpdate = true;
            }

            // Sync Dates
            if (!empty($tenant['expiry_date'])) {
                $tenantExpiry = Carbon::parse($tenant['expiry_date'])->format('Y-m-d');
                if ($plan->end_date !== $tenantExpiry) {
                    $updateData['end_date'] = $tenantExpiry;
                    $needsUpdate = true;
                }
            }

            if (!empty($tenant['start_date'])) {
                $tenantStart = Carbon::parse($tenant['start_date'])->format('Y-m-d');
                if ($plan->start_date !== $tenantStart) {
                    $updateData['start_date'] = $tenantStart;
                    $needsUpdate = true;
                }
            }

            // Sync Billing Cycle
            if (!empty($tenant['billing_cycle'])) {
                $cycle = str_contains(strtolower($tenant['billing_cycle']), 'month') ? 'month' : 'year';
                if ($plan->duration !== $cycle) {
                    $updateData['duration'] = $cycle;
                    $needsUpdate = true;
                }
            }

            // Sync Limits
            if (!empty($tenant['max_user']) && (int)$plan->user_limit !== (int)$tenant['max_user']) {
                $updateData['user_limit'] = (int)$tenant['max_user'];
                $needsUpdate = true;
            }

            if (!empty($tenant['max_branch']) && (int)$plan->branch_limit !== (int)$tenant['max_branch']) {
                $updateData['branch_limit'] = (int)$tenant['max_branch'];
                $needsUpdate = true;
            }

            // Sync Modules
            if (!empty($tenant['modules']) && is_array($tenant['modules'])) {
                $mods = array_values($tenant['modules']);
                if ($plan->features !== $mods) {
                    $updateData['features'] = $mods;
                    $needsUpdate = true;
                }
            }

            // Sync Subtitle
            $tenantClientName = $tenant['client_name'] ?? ($tenant['brand_name'] ?? null);
            if ($tenantClientName) {
                $sub = 'Purchased By ' . $tenantClientName;
                if ($plan->subtitle !== $sub) {
                    $updateData['subtitle'] = $sub;
                    $needsUpdate = true;
                }
            }

            if ($needsUpdate) {
                $plan->update($updateData);
                $plan->refresh();
            }
        }

        $paymentHistory = [];

        // 3. Fetch official transactions/payments from Super Admin for this client
        if ($clientId && $superAdminBase) {
            try {
                $resp = Http::timeout(6)->acceptJson()->withHeaders([
                    'X-Tenant-Secret' => config('tenant.secret'),
                ])->post("{$superAdminBase}/api/tenant/billing-history", [
                    'client_id' => $clientId,
                ]);

                if ($resp->successful() && !empty($resp->json('data'))) {
                    $paymentHistory = $resp->json('data');
                }
            } catch (\Throwable $e) {
                Log::warning('Failed fetching billing history from Super Admin: ' . $e->getMessage());
            }
        }

        // 4. Local fallback if Super Admin transactions could not be fetched
        if (empty($paymentHistory)) {
            $planHistory = Plan::latest('id')->get();
        } else {
            $planHistory = [];
        }

        return view('plans.myplan', compact('user', 'plan', 'planHistory', 'paymentHistory', 'tenant'));
    }

    public function myPlanDetails()
    {
        return $this->myplan();
    }

    /**
     * Get available upgrade plans from Super Admin (or local fallback).
     */
    public function getUpgradePlans(Request $request)
    {
        $tenant = $this->resolveTenantContext();
        $superAdminBase = $this->getSuperAdminBase();
        $memberships = [];

        if ($superAdminBase) {
            try {
                $resp = Http::timeout(6)->acceptJson()->get("{$superAdminBase}/api/memberships", [
                    'status' => 'Active',
                ]);

                if ($resp->successful() && !empty($resp->json('data'))) {
                    $memberships = $resp->json('data');
                }
            } catch (\Throwable $e) {
                Log::warning('Failed fetching memberships from Super Admin: ' . $e->getMessage());
            }
        }

        $currentPlan = auth()->user()->getActivePlan();

        return response()->json([
            'success'         => true,
            'current_plan'    => $currentPlan,
            'tenant'          => $tenant,
            'available_plans' => $memberships,
        ]);
    }

    /**
     * Create Razorpay order via Super Admin's Razorpay API (dynamically fetching Admin keys).
     */
    public function createRazorpayOrder(Request $request)
    {
        $validated = $request->validate([
            'payment_type'  => 'required|in:renewal,upgrade',
            'amount'        => 'required|numeric|min:0.01',
            'membership_id' => 'nullable|integer',
            'billing_cycle' => 'nullable|string',
        ]);

        $tenant = $this->resolveTenantContext();
        $clientId = $tenant['client_id'] ?? ($tenant['id'] ?? null);
        $superAdminBase = $this->getSuperAdminBase();

        $plan = auth()->user()->getActivePlan();
        $tenantRenewal = isset($tenant['renewal_amount']) ? (float)(is_array($tenant['renewal_amount']) ? ($tenant['renewal_amount'][0] ?? 0) : $tenant['renewal_amount']) : 0;
        $tenantAmount = isset($tenant['amount']) ? (float)(is_array($tenant['amount']) ? ($tenant['amount'][0] ?? 0) : $tenant['amount']) : 0;

        $amount = (float) ($validated['amount'] ?? 0);
        if ($amount <= 0 || ($validated['payment_type'] === 'renewal' && $amount == 1000.00)) {
            if ($validated['payment_type'] === 'renewal') {
                $amount = $tenantRenewal > 0 ? $tenantRenewal : (float)(($plan && (float)$plan->renewal_price > 0 && (float)$plan->renewal_price !== 1000.00) ? $plan->renewal_price : ($tenantAmount > 0 ? $tenantAmount : 49.00));
            } else {
                $amount = $tenantAmount > 0 ? $tenantAmount : (float)(($plan && (float)$plan->total_amount > 0 && (float)$plan->total_amount !== 1000.00) ? $plan->total_amount : 50.00);
            }
        }

        $user = auth()->user();
        $payload = [
            'amount'       => $amount,
            'client_id'    => $clientId,
            'payment_type' => $validated['payment_type'],
            'name'         => $tenant['client_name'] ?? ($tenant['brand_name'] ?? ($user->name ?? 'Insurance-CRM')),
            'email'        => $user->email,
            'contact'      => $user->phone ?? ($user->whatsapp ?? ''),
            'description'  => $validated['payment_type'] === 'renewal'
                ? 'Plan renewal payment for ' . ($tenant['client_name'] ?? ($user->name ?? 'Insurance-CRM'))
                : 'Plan upgrade payment for ' . ($tenant['client_name'] ?? ($user->name ?? 'Insurance-CRM')),
        ];

        if ($validated['payment_type'] === 'upgrade' && !empty($validated['membership_id'])) {
            $payload['membership_id'] = $validated['membership_id'];
            $payload['billing_cycle'] = $validated['billing_cycle'] ?? 'yearly';
        }

        try {
            $resp = Http::timeout(10)->acceptJson()->post("{$superAdminBase}/api/razorpay/create-order", $payload);

            if ($resp->successful() && !empty($resp->json('data'))) {
                return response()->json([
                    'success' => true,
                    'order'   => $resp->json('data'),
                ]);
            }

            Log::error('Razorpay create-order failed from Super Admin', [
                'url'      => "{$superAdminBase}/api/razorpay/create-order",
                'status'   => $resp->status(),
                'response' => $resp->body(),
                'payload'  => $payload,
            ]);

            $errorMsg = $resp->json('message')
                ?: ($resp->json('error')
                    ?: 'Failed to initialize payment gateway order. (HTTP ' . $resp->status() . ')');

            return response()->json([
                'success' => false,
                'message' => $errorMsg,
                'error'   => $resp->json() ?: substr($resp->body(), 0, 200),
            ], 400);
        } catch (\Throwable $e) {
            Log::error('Razorpay create-order exception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Unable to connect to Super Admin payment gateway: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify payment with Super Admin and synchronously sync client's local DB.
     */
    public function verifyRazorpayPayment(Request $request)
    {
        $validated = $request->validate([
            'razorpay_order_id'   => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature'  => 'required|string',
            'payment_type'        => 'required|in:renewal,upgrade',
            'amount'              => 'required|numeric',
            'membership_id'       => 'nullable|integer',
            'billing_cycle'       => 'nullable|string',
        ]);

        $tenant = $this->resolveTenantContext();
        $clientId = $tenant['client_id'] ?? ($tenant['id'] ?? null);
        $superAdminBase = $this->getSuperAdminBase();

        try {
            // 1. Verify payment with Super Admin API
            $verifyResp = Http::timeout(12)->acceptJson()->post("{$superAdminBase}/api/razorpay/verify-payment", [
                'razorpay_order_id'   => $validated['razorpay_order_id'],
                'razorpay_payment_id' => $validated['razorpay_payment_id'],
                'razorpay_signature'  => $validated['razorpay_signature'],
                'client_id'           => $clientId,
                'payment_type'        => $validated['payment_type'],
                'amount'              => $validated['amount'],
                'billing_cycle'       => $validated['billing_cycle'] ?? null,
            ]);

            if (!$verifyResp->successful() || !$verifyResp->json('success')) {
                Log::warning('Super Admin Razorpay verification rejected', [
                    'status'   => $verifyResp->status(),
                    'response' => $verifyResp->json() ?? $verifyResp->body(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $verifyResp->json('message') ?? 'Payment verification failed at Super Admin.',
                ], 400);
            }

            // 2. Clear tenant lookup cache so next request fetches fresh dates from Super Admin
            if ($tenant && !empty($tenant['domain'])) {
                app(\App\Services\Tenancy\TenantManager::class)->forgetCachedHost($tenant['domain']);
            }

            // 3. Update the local client tenant database immediately & accurately
            $currentPlan = auth()->user()->getActivePlan()
                ?? auth()->user()->getLatestPlan()
                ?? Plan::latest('id')->first();
            $now = Carbon::now();

            if ($validated['payment_type'] === 'renewal') {
                $cycle = $currentPlan ? strtolower(trim((string)$currentPlan->duration)) : 'year';
                if (!empty($validated['billing_cycle'])) {
                    $cycle = strtolower(trim((string)$validated['billing_cycle']));
                }

                $today = Carbon::today();
                $currentEndDate = ($currentPlan && !empty($currentPlan->end_date))
                    ? Carbon::parse($currentPlan->end_date)->startOfDay()
                    : null;

                $isActivePlan = ($currentEndDate && $currentEndDate->greaterThanOrEqualTo($today));
                $renewalStartDate = $isActivePlan
                    ? $currentEndDate->toDateString()
                    : $today->toDateString();

                $baseDateForExpiry = Carbon::parse($renewalStartDate);
                $newEndDate = (str_contains($cycle, 'month'))
                    ? $baseDateForExpiry->copy()->addMonth()->toDateString()
                    : $baseDateForExpiry->copy()->addYear()->toDateString();

                if ($currentPlan) {
                    $currentPlan->update([
                        'is_active'    => 1,
                        'duration'     => str_contains($cycle, 'month') ? 'month' : 'year',
                        'start_date'   => $renewalStartDate,
                        'end_date'     => $newEndDate,
                        'total_amount' => $validated['amount'],
                    ]);

                    User::where('id', auth()->id())->update([
                        'plan_id' => $currentPlan->id,
                    ]);
                }
            } elseif ($validated['payment_type'] === 'upgrade' && !empty($validated['membership_id'])) {
                // Fetch the upgraded membership info
                $membershipResp = Http::timeout(6)->acceptJson()->get("{$superAdminBase}/api/memberships/{$validated['membership_id']}");
                $membershipData = $membershipResp->successful() ? $membershipResp->json('data') : null;

                $planName = $membershipData['plan_name'] ?? 'Upgraded Plan';
                $features = [];
                if (!empty($membershipData['project_modules_data'])) {
                    $features = array_column($membershipData['project_modules_data'], 'name');
                }

                $cycle = strtolower(trim((string) ($validated['billing_cycle'] ?? 'year')));
                $newEndDate = (str_contains($cycle, 'month'))
                    ? $now->copy()->addMonth()->format('Y-m-d')
                    : $now->copy()->addYear()->format('Y-m-d');

                Plan::where('is_active', 1)->update(['is_active' => 0]);

                $newPlan = Plan::create([
                    'name'          => $planName,
                    'price'         => $validated['amount'],
                    'final_price'   => $validated['amount'],
                    'total_amount'  => $validated['amount'],
                    'total_price'   => $validated['amount'],
                    'renewal_price' => !empty($membershipData['renewal_amount']) ? (is_array($membershipData['renewal_amount']) ? reset($membershipData['renewal_amount']) : $membershipData['renewal_amount']) : null,
                    'duration'      => (str_contains($cycle, 'month')) ? 'month' : 'year',
                    'start_date'    => $now->format('Y-m-d'),
                    'end_date'      => $newEndDate,
                    'subtitle'      => 'Upgraded via Razorpay Checkout',
                    'user_limit'    => $membershipData['max_user'] ?? null,
                    'branch_limit'  => $membershipData['max_branch'] ?? null,
                    'is_active'     => 1,
                    'features'      => $features,
                ]);

                User::where('id', auth()->id())->update([
                    'plan_id' => $newPlan->id,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment verified and plan updated successfully!',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error during payment processing: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate Tax Invoice / Receipt PDF for a subscription plan matching design.
     */
    public function downloadInvoicePdf($id = null)
    {
        $user = auth()->user();
        $plan = $user->getActivePlan() ?? Plan::latest('id')->first();

        if (!$plan) {
            return redirect()->back()->with('error', 'Plan not found.');
        }

        $superAdminBase = $this->getSuperAdminBase();
        $tenant = function_exists('tenant') ? tenant() : [];
        $productId = $tenant['product_id'] ?? null;
        $projectId = $tenant['project_id'] ?? null;
        $clientId = $tenant['client_id'] ?? null;

        $companyDetails = [];
        $termsList = [];

        if ($superAdminBase) {
            try {
                $resp = Http::timeout(5)->acceptJson()->get($superAdminBase . '/api/invoice-details', [
                    'product_id' => $productId,
                    'project_id' => $projectId,
                ]);
                if ($resp->successful() && !empty($resp->json('data'))) {
                    $data = $resp->json('data');
                    $companyDetails = $data['company_details'] ?? [];
                    $termsList = $data['terms'] ?? [];
                }
            } catch (\Throwable $e) {
                Log::warning('Invoice details fetch error: ' . $e->getMessage());
            }
        }

        $transactionId = request('transaction_id') ?: ($id && is_numeric($id) && $id > 10 ? $id : null);
        $transactionData = null;

        if ($clientId && $transactionId && $superAdminBase) {
            try {
                $txResp = Http::timeout(5)->acceptJson()->withHeaders([
                    'X-Tenant-Secret' => config('tenant.secret'),
                ])->post("{$superAdminBase}/api/tenant/billing-history", [
                    'client_id'      => $clientId,
                    'transaction_id' => $transactionId,
                ]);

                if ($txResp->successful() && !empty($txResp->json('data'))) {
                    $transactionData = $txResp->json('data')[0] ?? null;
                }
            } catch (\Throwable $e) {
                Log::warning('Failed fetching specific transaction: ' . $e->getMessage());
            }
        }

        if (empty($companyDetails['company_name'])) {
            $companyDetails['company_name'] = 'FABLEAD DEVELOPERS TECHNOLAB';
        }
        if (empty($companyDetails['address'])) {
            $companyDetails['address'] = 'Ascon Plaza, A/5001, Behind Bulka Bhavan School, Adajan, Surat, Gujarat 395009';
        }
        if (empty($companyDetails['contact'])) {
            $companyDetails['contact'] = '9898989898';
        }
        if (empty($companyDetails['email'])) {
            $companyDetails['email'] = 'info@fableadtechnolabs.com';
        }

        if (empty($termsList)) {
            $termsList = [
                'This invoice is valid for the period mentioned in the order details.',
                'Payment is non-refundable once the service is activated.',
                'Renewal will be applicable as per the mentioned amount and validity.',
                'For any support or query, please contact us at the provided email or phone number.',
            ];
        }

        $customerName = $tenant['client_name'] ?? $user->name;
        $companyName = $tenant['brand_name'] ?? ($tenant['client_name'] ?? ($user->company_name ?? $customerName));
        $customerPhone = $user->phone ?? ($user->whatsapp ?? ($tenant['phone'] ?? ''));
        $customerEmail = $user->email;

        $invoiceYear = date('Y');
        $invoiceNo = $transactionData && !empty($transactionData['id'])
            ? 'INV-' . Carbon::parse($transactionData['created_at'])->format('Y') . '-' . str_pad((string)$transactionData['id'], 4, '0', STR_PAD_LEFT)
            : 'INV-' . $invoiceYear . '-' . str_pad((string)$plan->id, 4, '0', STR_PAD_LEFT);

        $orderId = $transactionData['razorpay_order_id'] ?? ($transactionData['id'] ?? (string)$plan->id);
        $orderDate = $transactionData && !empty($transactionData['created_at'])
            ? Carbon::parse($transactionData['created_at'])->format('d M Y')
            : ($plan->start_date ? Carbon::parse($plan->start_date)->format('d M Y') : date('d M Y'));

        $paymentMethod = $transactionData['payment_method'] ?? 'Razorpay';
        $paymentStatus = 'Completed (Paid)';

        if ($transactionData) {
            $plan = clone $plan;
            if (!empty($transactionData['amount'])) {
                $plan->total_amount = (float) $transactionData['amount'];
                $plan->price = (float) $transactionData['amount'];
            }
            if (!empty($transactionData['plan_name'])) {
                $plan->name = $transactionData['plan_name'];
            }
            if (!empty($transactionData['billing_cycle'])) {
                $plan->duration = $transactionData['billing_cycle'];
            }
            if (!empty($transactionData['period_start'])) {
                $plan->start_date = $transactionData['period_start'];
            }
            if (!empty($transactionData['period_end'])) {
                $plan->end_date = $transactionData['period_end'];
            }
            if (!empty($transactionData['payment_type'])) {
                $plan->subtitle = ucfirst($transactionData['payment_type']) . ' Payment';
            }
        }

        $validity = ($plan->start_date ? Carbon::parse($plan->start_date)->format('Y-m-d') : date('Y-m-d')) . ' to ' . ($plan->end_date ? Carbon::parse($plan->end_date)->format('Y-m-d') : date('Y-m-d'));

        $featuredModules = [];
        if (!empty($tenant['modules']) && is_array($tenant['modules'])) {
            $featuredModules = array_values($tenant['modules']);
        } elseif (!empty($plan->features) && is_array($plan->features)) {
            $featuredModules = array_values($plan->features);
        } else {
            $featuredModules = ['Client Manage', 'Sales CRM', 'Policy Center', 'Commissions', 'Withdrawal Requests', 'Operations', 'Sub Agent'];
        }

        $productTitle = $tenant['product_code'] ?? 'Insurance CRM';

        $pdfData = [
            'company'          => $companyDetails,
            'customer'         => [
                'name'    => $customerName,
                'company' => $companyName,
                'phone'   => $customerPhone,
                'email'   => $customerEmail,
            ],
            'order'            => [
                'status'         => $paymentStatus,
                'invoice_no'     => $invoiceNo,
                'order_id'       => $orderId,
                'date'           => $orderDate,
                'payment_method' => $paymentMethod,
                'validity'       => $validity,
            ],
            'plan'             => $plan,
            'product_title'    => $productTitle,
            'featured_modules' => $featuredModules,
            'terms'            => $termsList,
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('plans.invoice_pdf', $pdfData)
            ->setPaper('A4', 'portrait')
            ->setOptions([
                'defaultFont'          => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => true,
            ]);

        return $pdf->download("invoice-{$invoiceNo}.pdf");
    }
}

