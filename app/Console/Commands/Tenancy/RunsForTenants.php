<?php

namespace App\Console\Commands\Tenancy;

use App\Exceptions\TenantResolutionException;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\Process;

/**
 * Trait to execute artisan sub-processes isolated per tenant with TENANT_ID set.
 */
trait RunsForTenants
{
    /**
     * @return array<int, array>|null
     */
    protected function tenantsOrFail(TenantManager $tenancy, bool $onlyUsable, ?int $tenantId = null): ?array
    {
        if (!$tenancy->enabled()) {
            $this->error('Tenancy is disabled. Set TENANCY_ENABLED=true in .env first.');

            return null;
        }

        try {
            $tenants = $tenancy->all($tenantId ?: null);
        } catch (TenantResolutionException $e) {
            $this->error("[{$e->reason}] {$e->getMessage()}");

            return null;
        }

        if ($onlyUsable) {
            $tenants = array_values(array_filter($tenants, fn ($t) => $tenancy->isUsable($t)));
        }

        if ($tenants === []) {
            $this->warn('No matching tenants found.');
        }

        return $tenants;
    }

    /**
     * Run an artisan command for a specific tenant in a dedicated subprocess.
     *
     * @param array $tenant
     * @param array<int, string> $arguments
     * @return bool
     */
    protected function runArtisanForTenant(array $tenant, array $arguments): bool
    {
        $label = sprintf('#%d %s (%s)', $tenant['id'], $tenant['client_name'] ?? '', $tenant['db']['database'] ?? '?');
        $this->line("<fg=cyan>▶ {$label}</>: php artisan " . implode(' ', $arguments));

        $result = Process::path(base_path())
            ->env(['TENANT_ID' => (string) $tenant['id']])
            ->forever()
            ->run(array_merge([PHP_BINARY, 'artisan'], $arguments), function (string $type, string $output) {
                $this->output->write($output);
            });

        if (!$result->successful()) {
            $this->error("  ✗ failed for {$label} (exit {$result->exitCode()})");

            return false;
        }

        return true;
    }
}

