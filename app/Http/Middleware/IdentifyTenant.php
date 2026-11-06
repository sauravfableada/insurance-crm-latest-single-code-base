<?php

namespace App\Http\Middleware;

use App\Exceptions\TenantResolutionException;
use App\Services\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the tenant for the current HTTP request.
 * Must run before anything accesses the database, auth tokens, or filesystem storage.
 */
class IdentifyTenant
{
    public function __construct(private readonly TenantManager $tenancy)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->tenancy->enabled()) {
            $this->syncStaleTenantPermissions($request);
            return $next($request);
        }

        // 1. If an active tenant session, header, or cookie exists, restore and re-validate
        $activeTenantId = null;
        if ($request->hasSession() && $request->session()->has('tenant_id')) {
            $activeTenantId = (int) $request->session()->get('tenant_id');
        } elseif ($request->header('X-Tenant-Id')) {
            $activeTenantId = (int) $request->header('X-Tenant-Id');
        } elseif ($request->cookie('crm_tenant_id')) {
            $activeTenantId = (int) $request->cookie('crm_tenant_id');
        } elseif (!empty($_COOKIE['crm_tenant_id']) && is_numeric($_COOKIE['crm_tenant_id'])) {
            $activeTenantId = (int) $_COOKIE['crm_tenant_id'];
        }

        if ($activeTenantId) {
            try {
                $tenantId = $activeTenantId;
                $forceFresh = $request->has('refresh_tenant') 
                    || $request->has('refresh_permissions')
                    || ($request->hasSession() && (time() - (int) $request->session()->get('tenant_synced_at', 0) > 60));

                $tenant = $this->tenancy->resolveById($tenantId, $forceFresh);
                if ($request->hasSession()) {
                    $request->session()->put('tenant', $tenant);
                    $request->session()->put('tenant_id', $tenantId);
                    $request->session()->put('tenant_synced_at', time());
                }

                return $next($request);
            } catch (TenantResolutionException $e) {
                // If remote Admin-Product API is temporarily unreachable, fallback to cached session tenant
                $cachedTenant = $request->session()->get('tenant');
                if (is_array($cachedTenant) && !empty($cachedTenant['db']['database']) && $e->reason === 'UNREACHABLE') {
                    try {
                        $this->tenancy->activate($cachedTenant);
                        return $next($request);
                    } catch (\Throwable) {
                        // proceed to deny
                    }
                }

                // If tenant is expired or inactive, cleanly forget session and deny
                if ($request->hasSession()) {
                    $request->session()->forget(['tenant_id', 'tenant']);
                }
                Auth::guard('web')->logout();
                Auth::guard('customer')->logout();

                return $this->deny($request, $e);
            }
        }

        // 2. If user is already authenticated via session cookie but tenant_id is missing from session,
        // recover tenant using authenticated user's email or cleanly log out to prevent redirect loops.
        if ($request->hasSession() && (Auth::guard('web')->check() || Auth::guard('customer')->check())) {
            $user = Auth::guard('web')->user() ?? Auth::guard('customer')->user();
            if ($user && !empty($user->email)) {
                try {
                    $tenant = $this->tenancy->resolveByEmail($user->email);
                    $request->session()->put('tenant_id', $tenant['id']);
                    $request->session()->put('tenant', $tenant);

                    return $next($request);
                } catch (\Throwable) {
                    Auth::guard('web')->logout();
                    Auth::guard('customer')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
            } else {
                Auth::guard('web')->logout();
                Auth::guard('customer')->logout();
                $request->session()->invalidate();
            }
        }

        $centralHost = static::isCentralHost($request->getHost());

        // 3. For public authentication routes, pass through
        if (
            $request->is('/') ||
            $request->is('login') ||
            $request->is('logout') ||
            $request->is('customer/login') ||
            $request->is('customer/logout') ||
            $request->is('api/login') ||
            $request->is('api/v1/login') ||
            $request->is('api/*/login') ||
            $request->is('api/customer-login') ||
            $request->is('api/v1/customer-login') ||
            $request->is('api/*/customer-login') ||
            $request->is('storage/*') ||
            $request->is('public/storage/*') ||
            $request->is('uploads/*') ||
            $request->is('public/uploads/*') ||
            $request->is('webhook/*') ||
            $request->is('api/webhook/*')
        ) {
            if (!$centralHost) {
                // On client subdomain, resolve that tenant
                try {
                    $this->tenancy->resolveByHost($request->getHost());

                    // Auto-authenticate tenant admin on dedicated tenant domains so no login is required
                    if (!Auth::guard('web')->check() && !session('explicitly_logged_out') && !$request->is('logout') && !$request->has('login')) {
                        $tenant = $this->tenancy->current();
                        if ($tenant) {
                            $clientEmail = $tenant['client']['work_email'] ?? null;
                            $clientUser = $tenant['client']['username'] ?? null;

                            $admin = null;
                            if ($clientEmail) {
                                $admin = \App\Models\User::where('email', $clientEmail)->first();
                            }
                            if (!$admin && $clientUser) {
                                $admin = \App\Models\User::where('username', $clientUser)->first();
                            }
                            if (!$admin) {
                                $admin = \App\Models\User::where(function ($q) {
                                    $q->where('role', 1)->orWhere('role', '1')->orWhere('type', 'admin');
                                })->first();
                            }
                            if (!$admin) {
                                $admin = \App\Models\User::orderBy('id')->first();
                            }

                            if ($admin) {
                                Auth::guard('web')->login($admin, true);
                                if ($request->hasSession()) {
                                    $request->session()->put('tenant_id', $tenant['id']);
                                    $request->session()->put('tenant', $tenant);
                                }
                            }
                        }
                    }
                } catch (TenantResolutionException $e) {
                    // Non-fatal on public landing/login so user sees the login view
                }
            }
            return $next($request);
        }

        // 4. On central domain without a tenant session, redirect unauthenticated users to login
        if ($centralHost) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'status' => false,
                    'error' => 'Unauthenticated or tenant not selected.',
                ], 401);
            }

            if (!$request->is('/') && !$request->is('login')) {
                return redirect()->route('login');
            }

            return $next($request);
        }

        // 5. Otherwise, resolve by incoming domain host (subdomain-based access)
        try {
            $this->tenancy->resolveByHost($request->getHost());

            // Auto-authenticate tenant admin on dedicated tenant domains so no login is required
            if (!Auth::guard('web')->check() && !session('explicitly_logged_out') && !$request->is('logout') && !$request->has('login')) {
                $tenant = $this->tenancy->current();
                if ($tenant) {
                    $clientEmail = $tenant['client']['work_email'] ?? null;
                    $clientUser = $tenant['client']['username'] ?? null;

                    $admin = null;
                    if ($clientEmail) {
                        $admin = \App\Models\User::where('email', $clientEmail)->first();
                    }
                    if (!$admin && $clientUser) {
                        $admin = \App\Models\User::where('username', $clientUser)->first();
                    }
                    if (!$admin) {
                        $admin = \App\Models\User::where(function ($q) {
                            $q->where('role', 1)->orWhere('role', '1')->orWhere('type', 'admin');
                        })->first();
                    }
                    if (!$admin) {
                        $admin = \App\Models\User::orderBy('id')->first();
                    }

                    if ($admin) {
                        Auth::guard('web')->login($admin, true);
                        if ($request->hasSession()) {
                            $request->session()->put('tenant_id', $tenant['id']);
                            $request->session()->put('tenant', $tenant);
                        }
                    }
                }
            }
        } catch (TenantResolutionException $e) {
            return $this->deny($request, $e);
        }

        return $next($request);
    }

    /**
     * Respond with an appropriate error when tenant resolution fails.
     */
    public function deny(Request $request, TenantResolutionException $e): Response
    {
        $status = in_array($e->status, [401, 403, 404, 409, 500, 503], true) ? $e->status : 403;

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status' => false,
                'success' => false,
                'tenant_error' => $e->reason,
                'error' => $e->getMessage(),
                'message' => $e->getMessage(),
                'expiry_date' => $e->context['expiry_date'] ?? null,
            ], $status);
        }

        return response()->view('errors.tenant', [
            'reason' => $e->reason,
            'message' => $e->getMessage(),
            'expiryDate' => $e->context['expiry_date'] ?? null,
        ], $status);
    }

    /**
     * Check if the given hostname is a central host (where users log in before tenant is resolved).
     */
    public static function isCentralHost(string $host): bool
    {
        $host = TenantManager::normalizeHost($host);

        $configured = (array) config('tenant.central_hosts', []);
        $defaults = [
            'localhost',
            '127.0.0.1',
            'crm.fableadtech.com',
            'crm.fableadtech.in',
            'devcrm.fableadtech.com',
            'insurance.fableadtech.com',
            'mediumslateblue-owl-603065.hostingersite.com',
        ];

        $allCentral = array_map(fn($h) => TenantManager::normalizeHost((string)$h), array_merge($configured, $defaults));

        return in_array($host, $allCentral, true);
    }

    /**
     * Periodically synchronize tenant module permissions and plan details with Super Admin
     * even when the tenancy master switch is disabled (shared DB / single-database mode).
     */
    protected function syncStaleTenantPermissions(Request $request): void
    {
        // Don't sync on static assets or sync webhook
        if ($request->is('storage/*', 'public/*', 'uploads/*', 'webhook/*', 'api/tenant/sync-modules')) {
            return;
        }

        try {
            $lastSynced = $request->hasSession() ? (int) $request->session()->get('tenant_synced_at', 0) : 0;
            $now = time();
            $forceRefresh = $request->has('refresh_tenant') || $request->has('refresh_permissions');

            // Sync if never synced in this session, or older than 15 seconds, or forced
            if ($forceRefresh || ($now - $lastSynced) > 15) {
                /** @var \App\Http\Controllers\PlanController $planCtrl */
                $planCtrl = app(\App\Http\Controllers\PlanController::class);
                $resolved = $planCtrl->resolveTenantContext(true);

                if ($resolved && $request->hasSession()) {
                    $request->session()->put('tenant', $resolved);
                    $request->session()->put('tenant_id', $resolved['id'] ?? null);
                    $request->session()->put('tenant_synced_at', $now);
                    app()->instance('tenant', $resolved);
                }
            } elseif ($request->hasSession() && $request->session()->has('tenant')) {
                app()->instance('tenant', $request->session()->get('tenant'));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Auto sync tenant permissions failed: ' . $e->getMessage());
        }
    }
}

