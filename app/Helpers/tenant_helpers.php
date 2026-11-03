<?php

use App\Services\Tenancy\TenantManager;

if (!function_exists('tenant')) {
    /**
     * Get current client (tenant) configuration from Admin-Product, or a specific attribute.
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    function tenant(?string $key = null, $default = null)
    {
        $manager = app()->bound(TenantManager::class) ? app(TenantManager::class) : null;
        $tenant = $manager ? $manager->current() : null;

        if (!$tenant && app()->bound('tenant')) {
            $tenant = app('tenant');
        }

        if (!$tenant && session()->has('tenant')) {
            $tenant = session('tenant');
        }

        return $key === null ? $tenant : data_get($tenant, $key, $default);
    }
}

if (!function_exists('tenant_has_module')) {
    /**
     * Check if current tenant has access to given module name(s).
     * If tenancy is disabled and no tenant/plan context exists, returns true.
     * When tenant or plan configuration is present but has 0 assigned modules, returns false.
     * Automatically handles module name variants and aliases.
     *
     * @param string|array $moduleNames
     * @return bool
     */
    function tenant_has_module($moduleNames): bool
    {
        $hasContext = false;
        $assigned = null;

        // 1. Direct tenant configuration from TenantManager, app('tenant'), or session('tenant')
        $t = function_exists('tenant') ? tenant() : null;
        if (empty($t) && session()->has('tenant')) {
            $t = session('tenant');
        }

        if (is_array($t) && array_key_exists('modules', $t)) {
            $hasContext = true;
            $assigned = is_array($t['modules']) ? $t['modules'] : [];
        }

        // 2. Active subscription plan features from the database
        if ($assigned === null) {
            $plan = null;
            if (auth()->check()) {
                $plan = auth()->user()->getActivePlan();
            }
            if (!$plan) {
                $plan = \App\Models\Plan::where('is_active', 1)->latest('id')->first();
            }
            if ($plan && is_array($plan->features)) {
                $hasContext = true;
                $assigned = $plan->features;
            }
        }

        // If no tenant or plan context is active at all (standard non-tenant app without restrictions), allow all
        if (!$hasContext || $assigned === null) {
            return true;
        }

        // Core items that are always accessible even with 0 modules
        static $coreAlwaysAllowed = ['my profile', 'profile', 'my subscription', 'my plan', 'subscription', 'plan'];

        $checkList = is_array($moduleNames) ? $moduleNames : [$moduleNames];
        foreach ($checkList as $name) {
            $norm = strtolower(trim((string) $name));
            if (in_array($norm, $coreAlwaysAllowed, true)) {
                return true;
            }
        }

        // If tenant context exists and assigned modules is empty (admin gave 0 permissions), DENY ALL
        if (empty($assigned)) {
            return false;
        }

        $assignedNormalized = array_map(function ($m) {
            return strtolower(trim((string) $m));
        }, $assigned);

        static $aliasGroups = [
            // Dashboard
            ['dashboard', 'overview'],

            // Clients / Customers / Family Groups
            ['customers', 'customer', 'clients', 'client', 'client manage', 'manage clients', 'all clients', 'manage customers'],
            ['family groups', 'family group', 'family_groups'],

            // Sales CRM
            ['sales crm', 'sales', 'sales_crm', 'crm'],
            ['prospects', 'prospect', 'manage prospects', 'prospect management'],
            ['leads', 'lead', 'manage leads', 'manage lead'],
            ['follow ups', 'followups', 'followup', 'follow up', 'follow-ups', 'manage follow up', 'manage followups'],
            ['meetings', 'meeting', 'manage meetings'],

            // Policy Center / Policies / Life Insurance / Investments
            ['policy center', 'policy centre', 'policies', 'policy', 'manage policies', 'all policies'],
            ['insurance', 'life insurance', 'life_insurance', 'add insurance', 'manage insurance', 'renew insurance'],
            ['investments', 'investment', 'manage investment', 'add investment'],

            // Commissions (separate from withdrawal requests!)
            ['commissions', 'commission', 'commission rules', 'commission entries'],

            // Withdrawal Requests (independent)
            ['withdrawal requests', 'withdrawal request', 'withdrawals', 'withdrawal'],

            // Operations / Documents / Tasks / Endorsements
            ['operations', 'operation'],
            ['documents', 'document', 'document management', 'upload document', 'manage documents'],
            ['tasks', 'task', 'manage tasks'],
            ['endorsements', 'endorsement', 'alteration & endorsements', 'alterations', 'alteration and endorsements'],

            // Staff / Sub Agent / Users
            ['staff', 'users', 'user', 'manage staff', 'employees', 'employee'],
            ['sub agent', 'sub_agent', 'subagent'],
            ['team members', 'team member', 'team_members'],

            // Masters
            ['masters', 'master', 'manage masters', 'policy master'],

            // Claims
            ['claims', 'claim', 'claim tracker', 'claim_tracker', 'all claims', 'add claim'],

            // Target & Review
            ['target & review', 'target and review', 'target review', 'target_review', 'targets'],

            // Reports
            ['reports', 'report', 'manage reports', 'agent reports', 'policy reports', 'client reports', 'lead reports', 'followup reports'],

            // Marketing
            ['marketing', 'email marketing', 'email templates', 'email', 'sms marketing'],

            // Settings
            ['settings', 'setting', 'general settings'],
        ];

        $checkList = is_array($moduleNames) ? $moduleNames : [$moduleNames];
        $expandedCheckList = [];

        foreach ($checkList as $name) {
            $norm = strtolower(trim((string) $name));
            $expandedCheckList[$norm] = true;
            foreach ($aliasGroups as $group) {
                if (in_array($norm, $group, true)) {
                    foreach ($group as $alias) {
                        $expandedCheckList[$alias] = true;
                    }
                }
            }
        }

        foreach (array_keys($expandedCheckList) as $name) {
            if (in_array($name, $assignedNormalized, true)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('tenant_has_sub_module')) {
    /**
     * Check if current tenant has access to given sub-module name(s) / slug(s).
     *
     * @param string|array $subModuleNames
     * @param string|array|null $parentModuleName
     * @return bool
     */
    function tenant_has_sub_module($subModuleNames, $parentModuleName = null): bool
    {
        if ($parentModuleName !== null && !tenant_has_module($parentModuleName)) {
            return false;
        }

        $t = function_exists('tenant') ? tenant() : null;
        if (empty($t) && session()->has('tenant')) {
            $t = session('tenant');
        }

        if (!$t) {
            return true;
        }

        $rawNames = $t['sub_module_names'] ?? ($t['sub_modules'] ?? []);
        $activeNames = array_map(function ($m) {
            return strtolower(trim((string) $m));
        }, (array) $rawNames);
        $activeSlugs = array_map('strtolower', (array) ($t['sub_module_slugs'] ?? []));

        // If no sub-modules are tracked in tenant payload, default to parent module status
        if (empty($activeNames) && empty($activeSlugs)) {
            return $parentModuleName ? tenant_has_module($parentModuleName) : true;
        }

        $checkList = is_array($subModuleNames) ? $subModuleNames : [$subModuleNames];
        foreach ($checkList as $item) {
            $normalized = strtolower(trim((string) $item));
            if (in_array($normalized, $activeNames, true) || in_array($normalized, $activeSlugs, true)) {
                return true;
            }
        }

        // Check structured modules_with_sub_modules
        if ($parentModuleName !== null) {
            $modulesWithSubs = $t['modules_with_sub_modules'] ?? null;
            if (is_array($modulesWithSubs)) {
                $parentList = is_array($parentModuleName) ? $parentModuleName : [$parentModuleName];
                $parentListNormalized = array_map(fn($p) => strtolower(trim((string) $p)), $parentList);
                $foundInConfig = false;
                foreach ($modulesWithSubs as $mws) {
                    $modName = strtolower(trim((string) ($mws['name'] ?? '')));
                    if (in_array($modName, $parentListNormalized, true) || tenant_has_module($modName)) {
                        $foundInConfig = true;
                        $configuredSubs = (array) ($mws['sub_modules'] ?? []);
                        if (empty($configuredSubs)) {
                            return true;
                        }
                    }
                }
                if (!$foundInConfig && tenant_has_module($parentModuleName)) {
                    return true;
                }
            } else {
                return tenant_has_module($parentModuleName);
            }
        }

        return false;
    }
}

if (!function_exists('tenant_plan_name')) {
    function tenant_plan_name(): ?string
    {
        return tenant('plan_name');
    }
}

if (!function_exists('tenant_is_expired')) {
    function tenant_is_expired(): bool
    {
        return strcasecmp((string) tenant('membership_status'), 'Expired') === 0;
    }
}

if (!function_exists('tenant_storage_url')) {
    /**
     * Generate URL for tenant-isolated file.
     *
     * @param string $path
     * @return string
     */
    function tenant_storage_url(string $path): string
    {
        $clean = ltrim($path, '/\\');
        return url('storage/' . $clean);
    }
}

if (!function_exists('tenant_default_landing_url')) {
    /**
     * Get the default landing URL for the current tenant based on active module permissions.
     */
    function tenant_default_landing_url(): string
    {
        if (tenant_has_module('Dashboard')) {
            return route('dashboard');
        }
        if (tenant_has_module(['Leads', 'Sales CRM'])) {
            return route('leads.index');
        }
        if (tenant_has_module(['Policies', 'Policy Center'])) {
            return route('policies.index');
        }
        if (tenant_has_module(['Customers', 'Clients', 'Client Manage'])) {
            return route('masters.customers.index');
        }
        if (tenant_has_module('Prospects')) {
            return route('prospects.index');
        }
        if (tenant_has_module('Commissions')) {
            return route('commission-rules.index');
        }
        if (tenant_has_module('Withdrawal Requests')) {
            return route('admin.withdrawals.index');
        }
        if (tenant_has_module(['Operations', 'Documents'])) {
            return route('documents.index');
        }
        if (tenant_has_module(['Sub Agent', 'Staff', 'Users'])) {
            return route('users.index');
        }
        return route('plans.myplan');
    }
}

