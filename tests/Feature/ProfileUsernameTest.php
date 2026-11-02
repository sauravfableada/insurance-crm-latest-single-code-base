<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileUsernameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_profile_page_displays_username_field(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'insurance-crm@gmail.com'],
            [
                'name' => 'Insurance-CRM',
                'username' => 'insu_crm_admin',
                'phone' => '9898989898',
                'password' => Hash::make('12345678'),
                'is_active' => true,
                'role' => 3,
                'type' => 'admin',
            ]
        );
        $user->username = 'insu_crm_admin';
        $user->is_active = true;
        $user->save();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->get('/profile');
        $response->assertStatus(200);
        $response->assertSee('User Name');
        $response->assertSee('name="username"', false);
        $response->assertSee('insu_crm_admin');
    }

    public function test_user_can_update_their_username(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'insurance-crm@gmail.com'],
            [
                'name' => 'Insurance-CRM',
                'username' => 'insu_crm_admin',
                'phone' => '9898989898',
                'password' => Hash::make('12345678'),
                'is_active' => true,
                'role' => 3,
                'type' => 'admin',
            ]
        );
        $user->username = 'insu_crm_admin';
        $user->is_active = true;
        $user->save();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->post('/profile', [
            'name' => 'Insurance-CRM Updated',
            'username' => 'new_admin_username',
            'email' => 'insurance-crm@gmail.com',
            'phone' => '9898989898',
            'company_name' => 'My Insurance Company',
            'company_address' => '123 Main Street',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/profile');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'username' => 'new_admin_username',
            'name' => 'Insurance-CRM Updated',
        ]);
    }
}

