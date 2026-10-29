<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DynamicAdminSeederTest extends TestCase
{
    public function test_user_seeder_creates_dynamic_admin_from_configuration(): void
    {
        config([
            'app.admin_name' => 'Acme Insurance Corp',
            'app.admin_username' => 'acme_admin',
            'app.admin_email' => 'acme_admin@insurance.test',
            'app.admin_phone' => '+1 555-0199',
            'app.admin_password' => 'SecretAdmin99!',
        ]);

        Artisan::call('db:seed', ['--class' => UserSeeder::class, '--force' => true]);

        $admin = User::find(1);
        $this->assertNotNull($admin, 'Primary administrator user at ID 1 must exist.');
        $this->assertSame('Acme Insurance Corp', $admin->name);
        $this->assertSame('acme_admin', $admin->username);
        $this->assertSame('acme_admin@insurance.test', $admin->email);
        $this->assertSame('+1 555-0199', $admin->phone);
        $this->assertTrue(Hash::check('SecretAdmin99!', $admin->password));
        $this->assertTrue((bool) $admin->is_active);
        $this->assertSame('admin', $admin->type);
        $this->assertSame(3, (int) $admin->role);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->hasRole('admin'));

        // Dummy accounts must not exist
        $this->assertDatabaseMissing('users', ['email' => 'superadmin@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);

        // Web login test with dynamic username
        $response = $this->post('/login', [
            'login' => 'acme_admin',
            'password' => 'SecretAdmin99!',
        ]);
        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_user_seeder_cleans_up_stale_dummy_users(): void
    {
        // Seed dummy users first
        User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('12345678'),
            'is_active' => true,
        ]);

        config([
            'app.admin_name' => 'Insurance-CRM',
            'app.admin_username' => 'insu_crm_admin',
            'app.admin_email' => 'insurance-crm@gmail.com',
            'app.admin_phone' => '9898989898',
            'app.admin_password' => '12345678',
        ]);

        Artisan::call('db:seed', ['--class' => UserSeeder::class, '--force' => true]);

        $this->assertDatabaseMissing('users', ['email' => 'superadmin@example.com']);
        $admin = User::find(1);
        $this->assertNotNull($admin);
        $this->assertSame('insurance-crm@gmail.com', $admin->email);
        $this->assertSame('insu_crm_admin', $admin->username);
    }
}

