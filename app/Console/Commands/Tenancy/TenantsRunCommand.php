<?php

namespace App\Console\Commands\Tenancy;

use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

class TenantsRunCommand extends Command
{
    use RunsForTenants;

    protected $signature = 'tenants:run
                            {artisan_command : Artisan command to run, e.g. "optimize:clear"}
                            {--tenant= : Only run for this setup ID}
                            {--all : Include inactive and expired tenants}';

    protected $description = 'Run an arbitrary artisan command across tenant databases';

    public function handle(TenantManager $tenancy): int
    {
        $tenants = $this->tenantsOrFail($tenancy, ! $this->option('all'), (int) $this->option('tenant'));
        if ($tenants === null) {
            return self::FAILURE;
        }

        $commandParts = preg_split('/\s+/', trim((string) $this->argument('artisan_command')));
        $failed = 0;

        foreach ($tenants as $tenant) {
            $failed += $this->runArtisanForTenant($tenant, $commandParts) ? 0 : 1;
        }

        $this->newLine();
        $this->info(sprintf('Done: %d succeeded, %d failed.', count($tenants) - $failed, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

