<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class UserSeeder extends Seeder
{
    /**
     * Dynamically seed the primary administrator user using client parameters
     * from Admin-Product (Client Name, User Name, Admin Work Email, Phone Number, Password).
     */
    public function run(): void
    {
        // 1. Resolve dynamic client credentials across available sources
        $name = config('app.admin_name')
            ?: (function_exists('tenant') && tenant() ? (tenant('client_name') ?: tenant('brand_name') ?: tenant('name')) : null)
            ?: env('ADMIN_NAME', env('CLIENT_NAME'))
            ?: 'Insurance-CRM Admin';

        $username = config('app.admin_username')
            ?: (function_exists('tenant') && tenant() ? (tenant('username') ?: tenant('user_name')) : null)
            ?: env('ADMIN_USERNAME', env('CLIENT_USERNAME'))
            ?: 'insu_crm_admin';

        $email = config('app.admin_email')
            ?: (function_exists('tenant') && tenant() ? (tenant('email') ?: tenant('work_email')) : null)
            ?: env('ADMIN_EMAIL', env('CLIENT_EMAIL'))
            ?: 'insurance-crm@gmail.com';

        $phone = config('app.admin_phone')
            ?: (function_exists('tenant') && tenant() ? (tenant('phone') ?: tenant('mobile') ?: tenant('phone_number')) : null)
            ?: env('ADMIN_PHONE', env('CLIENT_PHONE'))
            ?: null;

        $password = config('app.admin_password')
            ?: (function_exists('tenant') && tenant() ? tenant('password') : null)
            ?: env('ADMIN_PASSWORD', env('CLIENT_PASSWORD'))
            ?: '12345678';

        // 2. If in tenant context and fields are missing, attempt lookup from admin_erp on the same MySQL host
        if (empty($phone) || empty($username) || $password === '12345678') {
            try {
                $adminDb = config('tenant.admin_database', 'admin_erp');
                $defaultConn = config('database.default', 'mysql');
                $host = config("database.connections.{$defaultConn}.host", '127.0.0.1');
                $user = config("database.connections.{$defaultConn}.username", 'root');
                $pass = config("database.connections.{$defaultConn}.password", '');

                $pdo = new \PDO("mysql:host={$host};dbname={$adminDb}", $user, $pass, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT,
                    \PDO::ATTR_TIMEOUT => 2,
                ]);

                $clientId = function_exists('tenant') && tenant() ? (tenant('client_id') ?? null) : null;
                $setupId = function_exists('tenant') && tenant() ? (tenant('id') ?? null) : null;

                $clientRow = null;
                if ($clientId) {
                    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
                    $stmt->execute([$clientId]);
                    $clientRow = $stmt->fetch(\PDO::FETCH_ASSOC);
                } elseif ($setupId) {
                    $stmt = $pdo->prepare("SELECT c.* FROM clients c JOIN setups s ON s.client_id = c.id WHERE s.id = ? LIMIT 1");
                    $stmt->execute([$setupId]);
                    $clientRow = $stmt->fetch(\PDO::FETCH_ASSOC);
                }

                if ($clientRow) {
                    $name = $clientRow['client_name'] ?: ($clientRow['brand_name'] ?: $name);
                    if (!empty($clientRow['username'])) {
                        $username = $clientRow['username'];
                    }
                    if (!empty($clientRow['work_email'])) {
                        $email = $clientRow['work_email'];
                    }
                    if (!empty($clientRow['mobile'])) {
                        $phone = $clientRow['mobile'];
                    }
                    if (!empty($clientRow['password'])) {
                        $password = $clientRow['password'];
                    }
                }
            } catch (\Throwable) {
                // Silently fall back to resolved values
            }
        }

        $email = strtolower(trim((string) $email));
        $username = strtolower(trim((string) $username));
        $hashedPassword = str_starts_with((string) $password, '$2y$') || str_starts_with((string) $password, '$2a$')
            ? $password
            : Hash::make((string) $password);

        // 3. Ensure Spatie roles exist and permissions are registered
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $superAdminRole = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        if (Permission::count() > 0) {
            $adminRole->syncPermissions(Permission::all());
            $superAdminRole->syncPermissions(Permission::all());
        }

        // 4. Find or target primary Admin user (ID = 1)
        $user = User::find(1);

        if (!$user) {
            // Check if user exists by email or username
            $user = User::where('email', $email)
                ->orWhere('username', $username)
                ->first();

            if (!$user) {
                // Check if any initial/legacy user exists
                $user = User::orderBy('id', 'asc')->first();
            }

            if ($user && (int) $user->id !== 1) {
                $oldId = (int) $user->id;
                try {
                    // Remap Spatie roles model_id to 1
                    DB::table('model_has_roles')
                        ->where('model_id', $oldId)
                        ->where('model_type', User::class)
                        ->update(['model_id' => 1]);

                    DB::table('users')->where('id', $oldId)->update(['id' => 1]);
                    $user = User::find(1);
                } catch (\Throwable) {
                    // If renumbering fails (e.g. strict foreign key constraint), proceed with existing instance
                }
            }
        }

        if (!$user) {
            $user = new User();
            $user->id = 1;
        }

        $user->name = $name;
        if (Schema::hasColumn('users', 'username')) {
            $user->username = $username;
        }
        $user->email = $email;
        if (Schema::hasColumn('users', 'phone') && !empty($phone)) {
            $user->phone = $phone;
        }
        $user->password = $hashedPassword;
        $user->is_active = true;
        if (Schema::hasColumn('users', 'type')) {
            $user->type = 'admin';
        }
        if (Schema::hasColumn('users', 'role')) {
            $user->role = 3;
        }
        $user->save();

        // 5. Assign Spatie admin roles
        $user->syncRoles(['admin', 'super-admin']);

        // 6. Clean up obsolete dummy placeholder users if they are not the primary admin
        User::where('id', '!=', $user->id)
            ->whereIn('email', ['superadmin@example.com', 'admin@example.com'])
            ->delete();

        if (isset($this->command)) {
            $this->command->info("Primary administrator seeded successfully: {$user->name} [username: {$user->username}, email: {$user->email}]");
        }
    }
}
