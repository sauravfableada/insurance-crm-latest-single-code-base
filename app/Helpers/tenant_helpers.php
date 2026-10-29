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

        return $key === null ? $tenant : data_get($tenant, $key, $default);
    }
}

if (!function_exists('tenant_has_module')) {
    /**
     * Check if current tenant has access to given module name(s).
     * If tenancy is disabled or tenant is null, returns true.
     * Automatically handles module name variants and aliases.
     *
     * @param string|array $moduleNames
     * @return bool
     */
    function tenant_has_module($moduleNames): bool
    {
        if (!config('tenant.enabled', false) || !tenant()) {
            return true;
        }

        $assigned = (array) tenant('modules', []);
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

            // Commissions
            ['commissions', 'commission', 'commission rules', 'commission entries', 'withdrawal requests', 'withdrawals'],

            // Operations / Documents / Tasks / Endorsements
            ['operations', 'operation'],
            ['documents', 'document', 'document management', 'upload document', 'manage documents'],
            ['tasks', 'task', 'manage tasks'],
            ['endorsements', 'endorsement', 'alteration & endorsements', 'alterations', 'alteration and endorsements'],

            // Staff / Sub Agent / Users
            ['staff', 'sub agent', 'sub_agent', 'users', 'user', 'manage staff', 'employees', 'employee'],
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
        if (!config('tenant.enabled', false) || !tenant()) {
            return true;
        }

        if ($parentModuleName !== null && !tenant_has_module($parentModuleName)) {
            return false;
        }

        $rawNames = tenant('sub_module_names') ?: tenant('sub_modules') ?: [];
        $activeNames = array_map(function ($m) {
            return strtolower(trim((string) $m));
        }, (array) $rawNames);
        $activeSlugs = array_map('strtolower', (array) tenant('sub_module_slugs', []));

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
            $modulesWithSubs = tenant('modules_with_sub_modules');
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

