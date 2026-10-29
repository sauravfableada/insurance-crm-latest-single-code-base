<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces plan-based module authorization on backend routes and APIs.
 * Prevents unauthorized access via direct URLs, route names, or API requests.
 */
class EnsureTenantModuleAccess
{
    /**
     * Map common route name prefixes or URI segments to tenant module names.
     */
    protected array $routeModuleMap = [
        'customers' => ['Customers', 'Clients'],
        'masters.customers' => ['Customers', 'Clients'],
        'masters.family_groups' => ['Family Groups'],
        'prospects' => ['Prospects'],
        'leads' => ['Leads'],
        'followups' => ['Followups'],
        'meetings' => ['Meetings'],
        'policies' => ['Policies', 'Insurance'],
        'life-insurance' => ['Life Insurance', 'Insurance', 'Policies'],
        'investment' => ['Investments'],
        'commission' => ['Commissions'],
        'commission-rules' => ['Commissions'],
        'commission-entries' => ['Commissions'],
        'admin.withdrawals' => ['Commissions'],
        'documents' => ['Documents'],
        'tasks' => ['Tasks'],
        'endorsement' => ['Alteration & Endorsements', 'Endorsements'],
        'users' => ['Staff', 'Users', 'Sub Agent'],
        'team-members' => ['Team Members', 'Staff'],
        'masters' => ['Masters', 'Manage Masters'],
        'claim-tracker' => ['Claim Tracker', 'Claims'],
        'targetandreview' => ['Target & Review', 'Reports'],
        'reports' => ['Reports'],
        'marketing' => ['Marketing', 'Email Marketing'],
    ];

    public function handle(Request $request, Closure $next, ?string $requiredModule = null): Response
    {
        if (!config('tenant.enabled', false) || !tenant()) {
            return $next($request);
        }

        // 1. Explicit module passed as middleware argument, e.g. tenant.module:leads
        if (!empty($requiredModule)) {
            $modulesToCheck = array_map('trim', explode('|', $requiredModule));
            if (!tenant_has_module($modulesToCheck)) {
                return $this->deny($request, $requiredModule);
            }
            return $next($request);
        }

        // 2. Automatically resolve module from route name
        $routeName = optional($request->route())->getName();
        if ($routeName) {
            foreach ($this->routeModuleMap as $pattern => $modules) {
                if ($routeName === $pattern || str_starts_with($routeName, $pattern . '.')) {
                    if (!tenant_has_module($modules)) {
                        return $this->deny($request, is_array($modules) ? $modules[0] : $modules);
                    }
                    break;
                }
            }
        }

        // 3. Fallback: check path segments if route name didn't match
        $path = trim($request->path(), '/');
        foreach ($this->routeModuleMap as $prefix => $modules) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/') || str_starts_with($path, 'api/' . $prefix)) {
                if (!tenant_has_module($modules)) {
                    return $this->deny($request, is_array($modules) ? $modules[0] : $modules);
                }
                break;
            }
        }

        return $next($request);
    }

    protected function deny(Request $request, string $module): Response
    {
        $message = "The '{$module}' module is not included in your current subscription plan.";

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status' => false,
                'success' => false,
                'error' => $message,
                'module' => $module,
            ], 403);
        }

        return redirect()->route('dashboard')->with('error', $message);
    }
}

