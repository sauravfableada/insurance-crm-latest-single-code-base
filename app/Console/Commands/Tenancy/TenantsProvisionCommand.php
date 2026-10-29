<?php

namespace App\Console\Commands\Tenancy;

use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

class TenantsProvisionCommand extends Command
{
    use RunsForTenants;

    protected $signature = 'tenants:provision
                            {tenant : Setup ID of the client in Admin-Product}
                            {--admin-email= : Login email for the initial admin (new databases only)}
                            {--admin-username= : Login username for the initial admin (new databases only)}
                            {--admin-password= : Password for the initial admin (new databases only)}';

    protected $description = 'Provision a client database: migrate, seed initial data, configure admin user';

    public function handle(TenantManager $tenancy): int
    {
        $tenants = $this->tenantsOrFail($tenancy, false, (int) $this->argument('tenant'));
        if (!$tenants) {
            return self::FAILURE;
        }

        $arguments = ['tenants:setup-current'];
        if ($email = $this->option('admin-email')) {
            $arguments[] = '--admin-email=' . $email;
        }
        if ($username = $this->option('admin-username')) {
            $arguments[] = '--admin-username=' . $username;
        }
        if ($password = $this->option('admin-password')) {
            $arguments[] = '--admin-password=' . $password;
        }

        return $this->runArtisanForTenant($tenants[0], $arguments) ? self::SUCCESS : self::FAILURE;
    }
}

