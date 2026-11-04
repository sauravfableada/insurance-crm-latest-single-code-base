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
        'dashboard' => ['Dashboard'],
        'customers' => ['Customers', 'Clients', 'All Clients'],
        'masters.customers' => ['Customers', 'Clients', 'All Clients'],
        'masters.family_groups' => ['Family Groups', 'Customers', 'Clients', 'All Clients'],
        'prospects' => ['Prospects'],
        'leads' => ['Leads', 'Sales CRM'],
        'followups' => ['Followups', 'Sales CRM'],
        'meetings' => ['Meetings', 'Sales CRM'],
        'policies.legacy' => ['Policies', 'Insurance', 'Policy Center'],
        'masters.policies' => ['Policies', 'Insurance', 'Policy Center'],
        'policies' => ['Policies', 'Insurance', 'Policy Center'],
        'upload-policy-ai' => ['Policies', 'Insurance', 'Policy Center'],
        'life-insurance' => ['Life Insurance', 'Insurance', 'Policies'],
        'investment' => ['Investments'],
        'commission-rules' => ['Commissions'],
        'commission-entries' => ['Commissions'],
        'commission' => ['Commissions'],
        'admin.withdrawals' => ['Withdrawal Requests'],
        'documents' => ['Documents', 'Operations'],
        'tasks' => ['Tasks', 'Operations'],
        'endorsement' => ['Alteration & Endorsements', 'Endorsements', 'Operations'],
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
        // Don't enforce restrictions on public auth or assets or sync routes or plan details
        if ($request->is('/', 'login', 'logout', 'api/login', 'storage/*', 'public/*', 'uploads/*', 'webhook/*', 'my-plan*', 'api/tenant/sync-modules')) {
            return $next($request);
        }

        // Determine if tenant restrictions exist
        $assigned = [];
        $t = function_exists('tenant') ? tenant() : null;
        if (!empty($t) && !empty($t['modules']) && is_array($t['modules'])) {
            $assigned = $t['modules'];
        } elseif (auth()->check()) {
            $plan = auth()->user()->getActivePlan();
            if ($plan && !empty($plan->features) && is_array($plan->features)) {
                $assigned = $plan->features;
            }
        }

        // If no tenant or plan module restrictions exist at all, allow access
        if (empty($assigned)) {
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

        $landing = function_exists('tenant_default_landing_url') ? tenant_default_landing_url() : route('plans.myplan');

        if ($request->url() === $landing || $request->fullUrl() === $landing) {
            abort(403, $message);
        }

        return redirect()->to($landing)->with('error', $message);
    }
}

