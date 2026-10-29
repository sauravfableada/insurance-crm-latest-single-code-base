<?php

namespace App\Console\Commands\Tenancy;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class TenantSetupCurrentCommand extends Command
{
    protected $signature = 'tenants:setup-current {--admin-email=} {--admin-username=} {--admin-password=}';

    protected $description = 'Internal: set up the tenant selected by TENANT_ID (invoked through tenants:provision)';

    protected $hidden = true;

    public function handle(): int
    {
        if (!tenant()) {
            $this->error('No tenant is active. Run this through: php artisan tenants:provision {id}');

            return self::FAILURE;
        }

        $this->info('Configuring database: ' . tenant('db.database'));

        if (Artisan::call('migrate', ['--force' => true], $this->output) !== 0) {
            return self::FAILURE;
        }

        // Only seed fresh databases that have no users yet
        if (User::query()->count() === 0) {
            $this->info('Seeding initial database...');
            if (Artisan::call('db:seed', ['--force' => true], $this->output) !== 0) {
                return self::FAILURE;
            }

            $email = $this->option('admin-email');
            $username = $this->option('admin-username');
            $password = $this->option('admin-password');

            if ($email || $password || $username) {
                // Find seeded admin user
                $admin = User::where('email', 'admin@example.com')
                    ->orWhere('email', 'superadmin@example.com')
                    ->first();

                if (!$admin) {
                    $admin = User::first();
                }

                if ($admin) {
                    if ($email) {
                        $admin->email = $email;
                    }
                    if ($username && Schema::hasColumn('users', 'username')) {
                        $admin->username = $username;
                    }
                    if ($password) {
                        $admin->password = Hash::make($password);
                    }
                    $admin->is_active = true;
                    $admin->save();

                    $this->info("Initial administrator account configured ({$admin->email}).");
                }
            }
        } else {
            $this->info('Database already contains records; skipping seeder.');
        }

        return self::SUCCESS;
    }
}

