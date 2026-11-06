<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema, Storage};
use Tests\TestCase;

class AiPolicyToolsTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.ai_tools_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('ai_tools_test');
        Schema::create('company_master', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->text('description')->nullable(); $table->boolean('status');
            $table->integer('created_by'); $table->integer('modified_by'); $table->timestamps(); $table->softDeletes();
        });
        $this->withoutMiddleware();
        $user = new class extends User { public function isAdmin(): bool { return true; } };
        $this->actingAs($user->forceFill(['id' => 10]));
    }

    protected function tearDown(): void
    {
        DB::purge('ai_tools_test'); DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_company_quick_add_creates_then_reuses_the_company(): void
    {
        $url = route('fablead.ai.quick-add', 'company');
        $first = $this->postJson($url, ['name' => 'Example Insurer'])->assertOk()->assertJsonPath('status', true);
        $this->postJson($url, ['name' => 'Example Insurer'])->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, DB::table('company_master')->count());
    }

    public function test_branch_requires_a_selected_parent_company(): void
    {
        $this->postJson(route('fablead.ai.quick-add', 'branch'), ['name' => 'Main branch'])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_extraction_json_is_available_only_to_its_uploader(): void
    {
        Storage::fake('local');
        $id = '123e4567-e89b-42d3-a456-426614174000';
        Storage::disk('local')->put("fablead_ai/extractions/{$id}.json", json_encode(['user_id' => 10, 'data' => ['fields' => []]]));
        $this->getJson(route('fablead.ai.json', $id))->assertOk()->assertJsonPath('user_id', 10);
        Storage::disk('local')->put("fablead_ai/extractions/{$id}.json", json_encode(['user_id' => 20, 'data' => []]));
        $this->getJson(route('fablead.ai.json', $id))->assertForbidden();
    }

    public function test_identical_policy_save_returns_duplicate_without_inserting_a_policy(): void
    {
        Schema::create('customers', function (Blueprint $table) { $table->id(); $table->softDeletes(); });
        Schema::create('policies', function (Blueprint $table) {
            $table->id(); $table->string('policy_number'); $table->string('policy_number_no')->nullable();
            $table->integer('company_id'); $table->date('start_date'); $table->date('end_date'); $table->softDeletes();
        });
        DB::table('customers')->insert(['id' => 1]);
        DB::table('company_master')->insert(['id' => 1, 'name' => 'Test insurer', 'status' => 1, 'created_by' => 10, 'modified_by' => 10]);
        DB::table('policies')->insert(['policy_number' => 'POL-0001', 'policy_number_no' => 'TEST-123', 'company_id' => 1, 'start_date' => '2026-10-20', 'end_date' => '2027-10-19']);
        Storage::fake('local');
        $id = '123e4567-e89b-42d3-a456-426614174000';
        Storage::disk('local')->put("fablead_ai/pending/{$id}/metadata.json", json_encode(['user_id' => 10]));
        $payload = ['upload_id' => $id, 'user_id' => 1, 'company_id' => 1, 'policy_type_id' => 1,
            'policy_number' => ' test-123 ', 'premium' => 100, 'start_date' => '2026-10-20', 'end_date' => '2027-10-19'];
        $this->postJson(route('fablead.ai.save'), $payload)->assertConflict()->assertJsonPath('duplicate', true)->assertJsonPath('status', false);
        $this->postJson(route('fablead.ai.save'), $payload)->assertConflict();
        $this->assertSame(1, DB::table('policies')->count());
        $check = new \App\Services\PolicyDuplicateCheck;
        $data = ['policy_number_no' => 'TEST-123', 'company_id' => 1, 'start_date' => '2027-10-20', 'end_date' => '2028-10-19'];
        $this->assertNull($check->find($data), 'A different renewal period is not the same policy term.');
        $data = ['policy_number_no' => 'TEST-123', 'company_id' => 2, 'start_date' => '2026-10-20', 'end_date' => '2027-10-19'];
        $this->assertNull($check->find($data), 'Different insurers may issue the same policy number.');
    }
}
