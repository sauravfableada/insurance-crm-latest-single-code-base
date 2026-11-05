<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Tenancy\AdminErpSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminErpSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_admin_erp_sync_service_resolves_and_updates_client_in_admin_erp(): void
    {
        // 1. Create or ensure client exists in admin_erp database for testing
        $testEmail = 'sync_test_' . time() . '@example.com';
        $sample = DB::connection('admin_erp')->table('clients')->first();
        $productId = $sample?->product_id ?? 2;
        $membershipId = $sample?->membership_id ?? 1;

        $clientId = DB::connection('admin_erp')->table('clients')->insertGetId([
            'client_name' => 'Old Client Name',
            'brand_name' => 'Old Brand',
            'work_email' => $testEmail,
            'mobile' => '1111111111',
            'product_id' => $productId,
            'membership_id' => $membershipId,
            'password' => Hash::make('OldPassword!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Create CRM admin user matching this client
        $user = User::create([
            'name' => 'Updated Client Name',
            'username' => 'updated_username',
            'email' => $testEmail,
            'phone' => '9999888877',
            'password' => Hash::make('NewSecret123!'),
            'is_active' => true,
            'role' => 3,
            'type' => 'admin',
        ]);
        $user->assignRole('admin');

        // 3. Perform sync
        $service = app(AdminErpSyncService::class);
        $resolvedId = $service->resolveClientId($user);
        $this->assertSame($clientId, $resolvedId);

        $synced = $service->syncClientProfile($user, [
            'company_name' => 'New Insurance Brand',
            'company_address' => '789 Commercial Blvd',
        ]);
        $this->assertTrue($synced);

        // 4. Verify admin_erp clients table updated
        $updatedClient = DB::connection('admin_erp')->table('clients')->where('id', $clientId)->first();
        $this->assertNotNull($updatedClient);
        $this->assertSame('Updated Client Name', $updatedClient->client_name);
        $this->assertSame('New Insurance Brand', $updatedClient->brand_name);
        $this->assertSame('9999888877', $updatedClient->mobile);

        // 5. Verify password sync (stored in plain text in admin_erp)
        $pwSynced = $service->syncClientPassword($user, 'BrandNewPassword999!');
        $this->assertTrue($pwSynced);

        $refreshedClient = DB::connection('admin_erp')->table('clients')->where('id', $clientId)->first();
        $this->assertSame('BrandNewPassword999!', $refreshedClient->password);

        // Clean up test client from admin_erp
        DB::connection('admin_erp')->table('clients')->where('id', $clientId)->delete();
        $user->delete();
    }

    public function test_profile_controller_syncs_to_admin_erp_on_update(): void
    {
        $testEmail = 'profile_sync_' . time() . '@example.com';
        $sample = DB::connection('admin_erp')->table('clients')->first();
        $productId = $sample?->product_id ?? 2;
        $membershipId = $sample?->membership_id ?? 1;

        $clientId = DB::connection('admin_erp')->table('clients')->insertGetId([
            'client_name' => 'Original Name',
            'brand_name' => 'Original Brand',
            'work_email' => $testEmail,
            'mobile' => '1234567890',
            'product_id' => $productId,
            'membership_id' => $membershipId,
            'password' => Hash::make('InitialPassword!'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Original Name',
            'username' => 'orig_user',
            'email' => $testEmail,
            'phone' => '1234567890',
            'password' => Hash::make('InitialPassword!'),
            'is_active' => true,
            'role' => 3,
            'type' => 'admin',
        ]);
        $user->assignRole('admin');

        $response = $this->actingAs($user)->post('/profile', [
            'name' => 'New Full Name',
            'username' => 'new_full_user',
            'email' => $testEmail,
            'phone' => '9876543210',
            'company_name' => 'Brand New Org',
            'company_address' => '456 Wall Street',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/profile');

        // Verify admin_erp clients table received the updates
        $updatedClient = DB::connection('admin_erp')->table('clients')->where('id', $clientId)->first();
        $this->assertSame('New Full Name', $updatedClient->client_name);
        $this->assertSame('Brand New Org', $updatedClient->brand_name);
        $this->assertSame('9876543210', $updatedClient->mobile);

        // Clean up
        DB::connection('admin_erp')->table('clients')->where('id', $clientId)->delete();
        $user->delete();
    }

    public function test_profile_controller_syncs_plain_text_password_to_admin_erp_on_update(): void
    {
        $testEmail = 'pw_sync_' . time() . '@example.com';
        $sample = DB::connection('admin_erp')->table('clients')->first();
        $productId = $sample?->product_id ?? 2;
        $membershipId = $sample?->membership_id ?? 1;

        $clientId = DB::connection('admin_erp')->table('clients')->insertGetId([
            'client_name' => 'Original Name',
            'brand_name' => 'Original Brand',
            'work_email' => $testEmail,
            'mobile' => '1234567890',
            'product_id' => $productId,
            'membership_id' => $membershipId,
            'password' => 'InitialPlainPassword',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Original Name',
            'username' => 'orig_user_' . time(),
            'email' => $testEmail,
            'phone' => '1234567890',
            'password' => Hash::make('CurrentSecret123!'),
            'is_active' => true,
            'role' => 3,
            'type' => 'admin',
        ]);
        $user->assignRole('admin');

        $response = $this->actingAs($user)->post('/profile/password', [
            'current_password' => 'CurrentSecret123!',
            'password' => 'UpdatedSecret999!',
            'password_confirmation' => 'UpdatedSecret999!',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        // 1. Verify tenant user table has hashed password and matches
        $refreshedUser = User::find($user->id);
        $this->assertTrue(Hash::check('UpdatedSecret999!', $refreshedUser->password));

        // 2. Verify admin_erp clients table has the password stored in plain text
        $updatedClient = DB::connection('admin_erp')->table('clients')->where('id', $clientId)->first();
        $this->assertSame('UpdatedSecret999!', $updatedClient->password);

        // Clean up
        DB::connection('admin_erp')->table('clients')->where('id', $clientId)->delete();
        $user->delete();
    }
}
