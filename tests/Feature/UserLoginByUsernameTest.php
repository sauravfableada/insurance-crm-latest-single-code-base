<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserLoginByUsernameTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('tenant.enabled', false);
    }

    public function test_user_can_be_created_with_username_in_fillable(): void
    {
        $uniqueUsername = 'testuser_' . uniqid();
        $uniqueEmail = 'test_' . uniqid() . '@example.test';

        $user = User::create([
            'name' => 'Test User',
            'username' => $uniqueUsername,
            'email' => $uniqueEmail,
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $this->assertEquals($uniqueUsername, $user->fresh()->username);
    }

    public function test_user_can_login_via_web_using_username(): void
    {
        $uniqueUsername = 'webuser_' . uniqid();
        $uniqueEmail = 'web_' . uniqid() . '@example.test';

        $user = User::create([
            'name' => 'Web User',
            'username' => $uniqueUsername,
            'email' => $uniqueEmail,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $response = $this->post(route('login.post'), [
            'username' => $uniqueUsername,
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_user_can_login_via_web_using_email_or_email_input_field(): void
    {
        $uniqueUsername = 'fielduser_' . uniqid();
        $uniqueEmail = 'field_' . uniqid() . '@example.test';

        $user = User::create([
            'name' => 'Field User',
            'username' => $uniqueUsername,
            'email' => $uniqueEmail,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        // Form field 'email' containing the username
        $response = $this->post(route('login.post'), [
            'email' => $uniqueUsername,
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_user_can_login_via_api_using_username(): void
    {
        $uniqueUsername = 'apiuser_' . uniqid();
        $uniqueEmail = 'api_' . uniqid() . '@example.test';

        $user = User::create([
            'name' => 'API User',
            'username' => $uniqueUsername,
            'email' => $uniqueEmail,
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/login', [
            'username' => $uniqueUsername,
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertEquals($uniqueEmail, $response->json('data.user.email'));
    }
}

