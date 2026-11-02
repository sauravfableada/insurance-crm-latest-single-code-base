<?php

namespace Tests\Feature;

use App\Models\FieldCustomization;
use App\Models\User;
use App\Services\FieldCustomizationService;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FieldCustomizationTest extends TestCase
{
    private User $admin;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $userRole = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);

        $this->admin = User::firstOrCreate(
            ['email' => 'fc_admin_test@example.com'],
            [
                'name' => 'FC Admin',
                'username' => 'fc_admin_test',
                'password' => Hash::make('password123'),
                'is_active' => true,
                'role' => 1,
                'type' => 'admin',
            ]
        );
        if (!$this->admin->hasRole('admin')) {
            $this->admin->assignRole($adminRole);
        }

        $this->regularUser = User::firstOrCreate(
            ['email' => 'fc_staff_test@example.com'],
            [
                'name' => 'FC Staff',
                'username' => 'fc_staff_test',
                'password' => Hash::make('password123'),
                'is_active' => true,
                'role' => 2,
                'type' => 'staff',
            ]
        );
        if ($this->regularUser->hasRole('admin')) {
            $this->regularUser->removeRole('admin');
        }
        if (!$this->regularUser->hasRole('staff')) {
            $this->regularUser->assignRole($userRole);
        }

        FieldCustomization::truncate();
        FieldCustomizationService::clearRuntimeCache();
    }

    protected function tearDown(): void
    {
        FieldCustomization::truncate();
        FieldCustomizationService::clearRuntimeCache();
        parent::tearDown();
    }

    public function test_non_admin_cannot_access_field_customization_endpoints(): void
    {
        $this->actingAs($this->regularUser);

        $getRes = $this->getJson(route('settings.field-customization.fields', 'policies'));
        $getRes->assertStatus(403);

        $postRes = $this->postJson(route('settings.field-customization.update', 'policies'), ['fields' => []]);
        $postRes->assertStatus(403);

        $resetRes = $this->postJson(route('settings.field-customization.reset', 'policies'));
        $resetRes->assertStatus(403);
    }

    public function test_admin_can_retrieve_module_fields(): void
    {
        $this->actingAs($this->admin);

        $response = $this->getJson(route('settings.field-customization.fields', 'policies'));
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'module' => [
                'key' => 'policies',
            ],
        ]);
        $response->assertJsonStructure([
            'success',
            'module' => [
                'key',
                'name',
                'icon',
                'description',
            ],
            'fields' => [
                '*' => [
                    'field_name',
                    'default_label',
                    'custom_label',
                    'is_visible',
                    'is_required',
                    'system_required',
                    'display_order',
                ],
            ],
        ]);
    }

    public function test_admin_can_save_field_customizations(): void
    {
        $this->actingAs($this->admin);

        $payload = [
            'fields' => [
                [
                    'field_name' => 'vehicle_reg_no',
                    'custom_label' => 'Vehicle Registration / Plate No',
                    'is_visible' => 1,
                    'is_required' => 1,
                    'validation_message' => 'Please enter vehicle registration number.',
                    'display_order' => 15,
                ],
                [
                    'field_name' => 'remarks',
                    'custom_label' => 'Internal Policy Remarks',
                    'is_visible' => 0,
                    'is_required' => 0,
                    'validation_message' => null,
                    'display_order' => 99,
                ],
            ],
        ];

        $response = $this->postJson(route('settings.field-customization.update', 'policies'), $payload);
        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Verify DB persistence
        $this->assertDatabaseHas('field_customizations', [
            'module' => 'policies',
            'field_name' => 'vehicle_reg_no',
            'custom_label' => 'Vehicle Registration / Plate No',
            'is_visible' => 1,
            'is_required' => 1,
            'validation_message' => 'Please enter vehicle registration number.',
            'display_order' => 15,
        ]);

        $this->assertDatabaseHas('field_customizations', [
            'module' => 'policies',
            'field_name' => 'remarks',
            'custom_label' => 'Internal Policy Remarks',
            'is_visible' => 0,
            'is_required' => 0,
            'display_order' => 99,
        ]);

        // Verify helper functions
        $this->assertEquals('Vehicle Registration / Plate No', field_label('policies', 'vehicle_reg_no'));
        $this->assertTrue(is_field_visible('policies', 'vehicle_reg_no'));
        $this->assertTrue(is_field_required('policies', 'vehicle_reg_no'));
        $this->assertEquals('Please enter vehicle registration number.', field_validation_message('policies', 'vehicle_reg_no'));

        $this->assertEquals('Internal Policy Remarks', field_label('policies', 'remarks'));
        $this->assertFalse(is_field_visible('policies', 'remarks'));
        $this->assertFalse(is_field_required('policies', 'remarks'));
    }

    public function test_module_customization_isolation(): void
    {
        $this->actingAs($this->admin);

        // Customize 'phone' in leads
        $this->postJson(route('settings.field-customization.update', 'leads'), [
            'fields' => [
                [
                    'field_name' => 'phone',
                    'custom_label' => 'Lead Direct Line',
                    'is_visible' => 1,
                    'is_required' => 1,
                    'validation_message' => 'Direct line is required.',
                    'display_order' => 12,
                ],
            ],
        ])->assertStatus(200);

        // Verify leads has custom label
        $this->assertEquals('Lead Direct Line', field_label('leads', 'phone'));

        // Verify customers 'phone' remains default
        $this->assertEquals('Phone Number', field_label('customers', 'phone'));
        $this->assertTrue(is_field_required('customers', 'phone'));
    }

    public function test_system_required_fields_cannot_be_hidden_or_made_optional(): void
    {
        $this->actingAs($this->admin);

        // 'policy_number' in policies is system_required: true
        $payload = [
            'fields' => [
                [
                    'field_name' => 'policy_number',
                    'custom_label' => 'Document Certificate No',
                    'is_visible' => 0, // attempt to hide
                    'is_required' => 0, // attempt to make optional
                    'display_order' => 5,
                ],
            ],
        ];

        $this->postJson(route('settings.field-customization.update', 'policies'), $payload)->assertStatus(200);

        // The service locks system_required fields to visible and required
        $record = FieldCustomization::where('module', 'policies')->where('field_name', 'policy_number')->first();
        $this->assertNotNull($record);
        $this->assertTrue((bool) $record->is_visible);
        $this->assertTrue((bool) $record->is_required);
        $this->assertEquals('Document Certificate No', $record->custom_label);
    }

    public function test_reset_to_default_restores_module_defaults(): void
    {
        $this->actingAs($this->admin);

        // Customize policies
        $this->postJson(route('settings.field-customization.update', 'policies'), [
            'fields' => [
                [
                    'field_name' => 'vehicle_model',
                    'custom_label' => 'Car Brand & Model',
                    'is_visible' => 1,
                    'is_required' => 1,
                    'display_order' => 1,
                ],
            ],
        ])->assertStatus(200);

        $this->assertEquals('Car Brand & Model', field_label('policies', 'vehicle_model'));

        // Reset policies
        $resetRes = $this->postJson(route('settings.field-customization.reset', 'policies'));
        $resetRes->assertStatus(200);
        $resetRes->assertJson(['success' => true]);

        // Verify records removed from DB
        $this->assertDatabaseMissing('field_customizations', [
            'module' => 'policies',
            'field_name' => 'vehicle_model',
        ]);

        // Helpers return system defaults
        $this->assertEquals('Vehicle Model', field_label('policies', 'vehicle_model'));
    }

    public function test_settings_page_displays_field_customization_tab(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/settings?tab=field-customization');
        $response->assertStatus(200);
        $response->assertSee('Field Customization');
        $response->assertSee('Module-Wise Field Customization');
        $response->assertSee('Select CRM Module:');
        $response->assertSee('Admin Controlled');
    }

    public function test_all_crm_modules_are_registered_and_retrievable(): void
    {
        $this->actingAs($this->admin);

        $expectedModules = [
            'policies',
            'customers',
            'leads',
            'prospects',
            'claim_trackers',
            'followups',
            'meetings',
            'tasks',
            'investments',
            'users',
            'deals',
            'quotations',
        ];

        foreach ($expectedModules as $mod) {
            $response = $this->getJson(route('settings.field-customization.fields', $mod));
            $response->assertStatus(200);
            $response->assertJson([
                'success' => true,
                'module' => [
                    'key' => $mod,
                ],
            ]);
            $this->assertNotEmpty($response->json('fields'));
        }
    }
}

