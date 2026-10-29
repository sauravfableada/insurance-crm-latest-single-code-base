<?php

namespace App\Console\Commands\Tenancy;

use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

class TenantsProvisionCommand extends Command
{
    use RunsForTenants;

    protected $signature = 'tenants:provision
                            {tenant : Setup ID of the client in Admin-Product}
                            {--admin-name= : Client name / organization name of the initial admin}
                            {--admin-username= : Login username for the initial admin}
                            {--admin-email= : Login email for the initial admin}
                            {--admin-phone= : Phone number for the initial admin}
                            {--admin-password= : Password for the initial admin}';

    protected $description = 'Provision a client database: migrate, seed initial data, configure admin user';

    public function handle(TenantManager $tenancy): int
    {
        $tenants = $this->tenantsOrFail($tenancy, false, (int) $this->argument('tenant'));
        if (!$tenants) {
            return self::FAILURE;
        }

        $arguments = ['tenants:setup-current'];
        if ($name = $this->option('admin-name')) {
            $arguments[] = '--admin-name=' . $name;
        }
        if ($username = $this->option('admin-username')) {
            $arguments[] = '--admin-username=' . $username;
        }
        if ($email = $this->option('admin-email')) {
            $arguments[] = '--admin-email=' . $email;
        }
        if ($phone = $this->option('admin-phone')) {
            $arguments[] = '--admin-phone=' . $phone;
        }
        if ($password = $this->option('admin-password')) {
            $arguments[] = '--admin-password=' . $password;
        }

        return $this->runArtisanForTenant($tenants[0], $arguments) ? self::SUCCESS : self::FAILURE;
    }
}
