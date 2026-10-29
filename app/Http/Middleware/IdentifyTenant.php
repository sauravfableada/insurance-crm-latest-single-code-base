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
            return $next($request);
        }

        // 1. If an active tenant session already exists, restore and re-validate
        if ($request->hasSession() && $request->session()->has('tenant_id')) {
            try {
                $tenantId = (int) $request->session()->get('tenant_id');
                $forceFresh = $request->has('refresh_tenant') || $request->has('refresh_permissions');

                $tenant = $this->tenancy->resolveById($tenantId, $forceFresh);
                $request->session()->put('tenant', $tenant);

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
            $request->is('api/customer-login') ||
            $request->is('storage/*') ||
            $request->is('public/storage/*') ||
            $request->is('uploads/*') ||
            $request->is('public/uploads/*') ||
            $request->is('webhook/*')
        ) {
            if (!$centralHost) {
                // On client subdomain, resolve that tenant
                try {
                    $this->tenancy->resolveByHost($request->getHost());
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
        ];

        $allCentral = array_map(fn($h) => TenantManager::normalizeHost((string)$h), array_merge($configured, $defaults));

        return in_array($host, $allCentral, true);
    }
}

