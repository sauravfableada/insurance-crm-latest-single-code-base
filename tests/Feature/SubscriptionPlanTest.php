<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SubscriptionPlanTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'admin_test_plan@example.com'],
            [
                'name'     => 'Plan Admin Test',
                'username' => 'plan_admin_test',
                'password' => bcrypt('password123'),
                'type'     => 'staff',
                'is_active'=> 1,
            ]
        );
    }

    public function test_authenticated_user_can_access_my_plan_page()
    {
        $plan = Plan::create([
            'name'          => 'Starter Plan',
            'price'         => 1000.00,
            'renewal_price' => 0.00,
            'total_amount'  => 1000.00,
            'total_price'   => 1000.00,
            'duration'      => 'month',
            'start_date'    => '2026-09-23',
            'end_date'      => '2028-01-01',
            'subtitle'      => 'Purchased By Fablead Demo Company',
            'user_limit'    => 10,
            'branch_limit'  => 1,
            'is_active'     => true,
            'features'      => ['Dashboard', 'Client Manage', 'Sales CRM', 'Policy Center'],
        ]);

        $this->user->update(['plan_id' => $plan->id]);

        $response = $this->actingAs($this->user)->get(route('plans.myplan'));

        $response->assertStatus(200);
        $response->assertSee('My Plan Details');
        $response->assertSee('Starter Plan');
        $response->assertSee('Included Features');
        $response->assertSee('Download Invoice');
        $response->assertSee('Renew Plan');
    }

    public function test_upgrade_options_returns_json()
    {
        $response = $this->actingAs($this->user)->getJson(route('plans.upgrade.options'));

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'current_plan',
                'available_plans',
            ]);
    }

    public function test_create_razorpay_order_validates_required_fields()
    {
        $response = $this->actingAs($this->user)->postJson(route('plans.razorpay.createOrder'), []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_type', 'amount']);
    }

    public function test_invoice_pdf_can_be_downloaded()
    {
        $plan = Plan::firstOrCreate(
            ['is_active' => true],
            [
                'name'         => 'Starter Plan',
                'price'        => 1000.00,
                'total_amount' => 1000.00,
                'duration'     => 'month',
                'start_date'   => '2026-09-23',
                'end_date'     => '2028-01-01',
                'features'     => ['Dashboard', 'Policies'],
            ]
        );

        $this->user->update(['plan_id' => $plan->id]);

        $response = $this->actingAs($this->user)->get(route('plans.invoice.pdf'));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }
}

