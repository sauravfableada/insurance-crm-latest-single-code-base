<?php

namespace App\Console\Commands\Tenancy;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class TenantSetupCurrentCommand extends Command
{
    protected $signature = 'tenants:setup-current 
                            {--admin-name=} 
                            {--admin-username=} 
                            {--admin-email=} 
                            {--admin-phone=} 
                            {--admin-password=}';

    protected $description = 'Internal: set up the tenant selected by TENANT_ID (invoked through tenants:provision)';

    protected $hidden = true;

    public function handle(): int
    {
        if (!tenant()) {
            $this->error('No tenant is active. Run this through: php artisan tenants:provision {id}');

            return self::FAILURE;
        }

        $this->info('Configuring database: ' . tenant('db.database'));

        // Push command options to runtime config so UserSeeder can consume them directly
        $name = $this->option('admin-name') ?: (tenant('client_name') ?: tenant('brand_name'));
        $username = $this->option('admin-username') ?: tenant('username');
        $email = $this->option('admin-email') ?: tenant('email');
        $phone = $this->option('admin-phone') ?: (tenant('phone') ?: tenant('mobile'));
        $password = $this->option('admin-password') ?: tenant('password');

        if ($name) {
            config(['app.admin_name' => $name]);
        }
        if ($username) {
            config(['app.admin_username' => $username]);
        }
        if ($email) {
            config(['app.admin_email' => $email]);
        }
        if ($phone) {
            config(['app.admin_phone' => $phone]);
        }
        if ($password) {
            config(['app.admin_password' => $password]);
        }

        // Run migrations
        if (Artisan::call('migrate', ['--force' => true], $this->output) !== 0) {
            return self::FAILURE;
        }

        // Seed initial database if fresh or if requested
        if (User::query()->count() === 0) {
            $this->info('Seeding initial database...');
            if (Artisan::call('db:seed', ['--force' => true], $this->output) !== 0) {
                return self::FAILURE;
            }
        } else {
            // Run UserSeeder to ensure primary admin credentials & roles are up-to-date
            $this->info('Updating tenant administrator credentials...');
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\UserSeeder', '--force' => true], $this->output);
        }

        // Direct guarantee: ensure admin user at ID 1 is active, typed 'admin', and assigned roles
        $admin = User::find(1)
            ?? ($email ? User::where('email', $email)->first() : null)
            ?? ($username ? User::where('username', $username)->first() : null)
            ?? User::first();

        if ($admin) {
            if ($name) {
                $admin->name = $name;
            }
            if ($email) {
                $admin->email = strtolower(trim($email));
            }
            if ($username && Schema::hasColumn('users', 'username')) {
                $admin->username = strtolower(trim($username));
            }
            if ($phone && Schema::hasColumn('users', 'phone')) {
                $admin->phone = $phone;
            }
            if ($password) {
                $admin->password = str_starts_with($password, '$2y$') || str_starts_with($password, '$2a$')
                    ? $password
                    : Hash::make($password);
            }
            $admin->is_active = true;
            if (Schema::hasColumn('users', 'type')) {
                $admin->type = 'admin';
            }
            if (Schema::hasColumn('users', 'role')) {
                $admin->role = 3;
            }
            $admin->save();

            // Ensure Spatie admin roles
            if (method_exists($admin, 'syncRoles')) {
                $admin->syncRoles(['admin', 'super-admin']);
            }

            $this->info("Initial administrator account verified and active ({$admin->email}).");
        }

        return self::SUCCESS;
    }
}
