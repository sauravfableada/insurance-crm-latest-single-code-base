<?php

namespace App\Console\Commands\Tenancy;

use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

class TenantsListCommand extends Command
{
    use RunsForTenants;

    protected $signature = 'tenants:list {--all : Include inactive and expired tenants}';

    protected $description = 'List all client tenants registered in Admin-Product for this product';

    public function handle(TenantManager $tenancy): int
    {
        $tenants = $this->tenantsOrFail($tenancy, ! $this->option('all'));
        if ($tenants === null) {
            return self::FAILURE;
        }

        $headers = ['ID', 'Client', 'Domain', 'Database', 'Status', 'Plan', 'Expiry'];
        $rows = array_map(fn ($t) => [
            $t['id'] ?? '-',
            $t['client_name'] ?? '-',
            $t['domain'] ?? '-',
            $t['db']['database'] ?? '-',
            $t['status'] ?? '-',
            $t['plan_name'] ?? '-',
            $t['expiry_date'] ?? '-',
        ], $tenants);

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}

