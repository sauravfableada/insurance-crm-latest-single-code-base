<?php

namespace Tests\Feature;

use App\Models\{Policy, User};
use App\Services\{AiPolicyRenewal, PolicyRenewalReview};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema, Storage};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AiPolicyRenewalTest extends TestCase
{
    private string $connection;
    private User $viewer;
    private Policy $source;
    private string $pdf;
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = DB::getDefaultConnection();
        config(['database.connections.ai_renewal_test'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
        DB::setDefaultConnection('ai_renewal_test');
        Schema::create('customers',function(Blueprint $t) { $t->id(); $t->string('name'); $t->string('phone')->nullable(); $t->date('dob')->nullable(); $t->softDeletes(); });
        Schema::create('company_master',function(Blueprint $t) { $t->id(); $t->string('name'); $t->softDeletes(); });
        Schema::create('insurance_policy_types',function(Blueprint $t) { $t->id(); $t->string('name'); $t->softDeletes(); });
        Schema::create('policies',function(Blueprint $t) {
            $t->id();
            foreach (array_unique(array_merge(array_values(AiPolicyRenewal::FIELDS),['policy_number','policy_number_no','holder_id','family_group_id','user_id','created_by','modified_by','renewal_of_policy_id','renew_id','is_renew','renew_status','status','policy_pdf','sub_type_id'])) as $column) $t->string($column)->nullable();
            $t->string('renewal_document_hash',64)->nullable()->unique(); $t->text('renewal_review')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('policy_pdf_histories',function(Blueprint $t) {
            $t->id(); $t->integer('policy_id'); foreach(['file_name','original_file_name','document_type','label','document_date','uploaded_by'] as $column) $t->string($column)->nullable(); $t->text('comment')->nullable(); $t->timestamps();
        });
        Schema::create('policy_members',function(Blueprint $t) { $t->id();$t->integer('policy_id');$t->string('name');$t->string('relation')->nullable();$t->date('dob')->nullable();$t->string('gender')->nullable();$t->timestamps(); });
        Schema::create('policy_addons',function(Blueprint $t) { $t->id();$t->integer('policy_id');$t->string('cover_name');$t->decimal('premium_amount')->nullable();$t->timestamps(); });
        DB::table('customers')->insert(['id'=>1,'name'=>'Test Holder','dob'=>'1980-01-01']);
        DB::table('company_master')->insert([['id'=>1,'name'=>'Old insurer'],['id'=>2,'name'=>'New insurer']]);
        foreach(['Health','General','Vehicle','Life','Travel','Marine','Fire','Burglary','Personal Accident','Other'] as $id=>$name) DB::table('insurance_policy_types')->insert(['id'=>$id+1,'name'=>$name]);
        $this->viewer = (new class extends User { public function isAdmin(): bool { return true; } })->forceFill(['id'=>10]);
        $this->source = Policy::create(['holder_id'=>1,'company_id'=>1,'policy_type_id'=>1,'policy_number'=>'INTERNAL-OLD','policy_number_no'=>'OLD-123','start_date'=>'2025-10-20','end_date'=>'2026-10-19','premium_amount'=>'1000','user_id'=>10,'created_by'=>10]);
        $this->source = $this->source->fresh();
        Storage::fake('local'); Storage::fake('public');
        Storage::disk('local')->put('renewal-test.pdf','%PDF-1.4 renewed policy contents');
        $this->pdf = Storage::disk('local')->path('renewal-test.pdf');
        $this->actingAs($this->viewer);
        $route=app('router')->getRoutes()->getByName('fablead.ai.renewal.review');
        $middleware=app('router')->resolveMiddleware($route->gatherMiddleware());
        $this->withoutMiddleware(array_values(array_filter($middleware,fn($class)=>$class !== \Illuminate\Routing\Middleware\SubstituteBindings::class)));
    }
    protected function tearDown(): void { DB::purge('ai_renewal_test'); DB::setDefaultConnection($this->connection); parent::tearDown(); }
    private function input(array $overrides=[]): array
    {
        return array_replace(['renewal_holder_name'=>'Test Holder','policy_number'=>'OLD-123','company_id'=>1,'policy_type_id'=>1,
            'start_date'=>'2026-10-20','end_date'=>'2027-10-19','premium'=>'1500','sum_assured'=>'500000',
            'covered_members'=>[['name'=>'Test Holder','relation'=>'Self','dob'=>'1980-01-01','gender'=>'Male']],
            'addon_covers'=>[['cover_name'=>'Extra cover','premium_amount'=>'100']],
            'renewal_confirmed'=>'1','coverage_confirmed'=>'1','manual_verified'=>'1','verification_reason'=>'Verified original policy and identity documents.'], $overrides);
    }
    private function audit(array $fields=[]): array
    {
        return ['upload_id'=>'123e4567-e89b-42d3-a456-426614174000','user_id'=>10,'renewal_source_id'=>$this->source->id,
            'renewal_source_fingerprint'=>(new PolicyRenewalReview)->fingerprint($this->source),
            'data'=>['fields'=>array_replace(['holder_name'=>['value'=>'Test Holder'],'policy_number'=>['value'=>'OLD-123']],$fields)]];
    }
    public function test_new_term_preserves_source_and_writes_separate_document_and_members(): void
    {
        $before=$this->source->getAttributes();
        $new=(new AiPolicyRenewal)->save($this->source,$this->viewer,$this->input(),$this->audit(),$this->pdf);
        $this->assertSame($before,$this->source->fresh()->getAttributes());
        $this->assertSame((int)$this->source->id,(int)$new->renewal_of_policy_id);
        $this->assertSame(1,(int)$new->holder_id);
        $this->assertCount(1,$new->members); $this->assertCount(1,$new->addons); $this->assertCount(1,$new->pdfHistories);
        Storage::disk('local')->assertExists($new->policy_pdf);
        $this->assertSame('2027-10-19',$new->end_date);
        $this->assertSame([$new->id],Policy::currentTerms()->pluck('id')->all());
    }
    public function test_changed_number_company_category_premium_and_coverage_are_allowed_after_verification(): void
    {
        $new=(new AiPolicyRenewal)->save($this->source,$this->viewer,$this->input(['policy_number'=>'NEW-456','company_id'=>2,'policy_type_id'=>2,'premium'=>'2500','sum_assured'=>'1000000']),$this->audit(['policy_number'=>['value'=>'NEW-456']]),$this->pdf);
        $this->assertSame('NEW-456',$new->policy_number_no); $this->assertSame(2,(int)$new->company_id); $this->assertSame(2,(int)$new->policy_type_id);
        $this->assertEquals(2500,$new->premium_amount); $this->assertEquals(1000000,$new->sum_assured);
    }
    public function test_unrelated_holder_is_blocked_even_when_number_matches_and_confirmations_are_supplied(): void
    {
        $this->expectException(ValidationException::class);
        try { (new AiPolicyRenewal)->save($this->source,$this->viewer,$this->input(),$this->audit(['holder_name'=>['value'=>'Completely Different Person']]),$this->pdf); }
        finally { $this->assertSame(1,Policy::count()); }
    }
    public function test_similar_name_can_be_saved_with_single_explicit_confirmation(): void
    {
        $input=$this->input();
        unset($input['coverage_confirmed'],$input['manual_verified'],$input['verification_reason']);
        $new=(new AiPolicyRenewal)->save($this->source,$this->viewer,$input,$this->audit(['holder_name'=>['value'=>'Test Holdar']]),$this->pdf);
        $this->assertSame(1,(int)$new->holder_id);
        $this->assertTrue(json_decode($new->renewal_review,true)['review']['manual_required']);
    }
    public function test_changed_number_still_requires_explicit_save_confirmation(): void
    {
        $this->expectException(ValidationException::class);
        (new AiPolicyRenewal)->save($this->source,$this->viewer,$this->input(['renewal_confirmed'=>null]),$this->audit(['policy_number'=>['value'=>'NEW-456']]),$this->pdf);
    }
    public function test_duplicate_document_and_second_renewal_do_not_create_another_term(): void
    {
        $service=new AiPolicyRenewal;
        $service->save($this->source,$this->viewer,$this->input(),$this->audit(),$this->pdf);
        try { $service->save($this->source,$this->viewer,$this->input(),$this->audit(),$this->pdf); $this->fail('Duplicate accepted'); }
        catch(ValidationException $e) { $this->assertArrayHasKey('duplicate',$e->errors()); }
        $this->assertSame(2,Policy::count());
    }
    public static function categories(): array
    {
        return array_combine(['Health','General','Vehicle','Life','Travel','Marine','Fire','Burglary','Personal Accident','Other'],array_map(fn($id)=>[$id],range(1,10)));
    }
    /** @dataProvider categories */
    public function test_each_supported_category_can_save_an_independent_linked_renewal(int $id): void
    {
        $this->source->update(['policy_type_id'=>$id]);
        $new=(new AiPolicyRenewal)->save($this->source,$this->viewer,$this->input(['policy_type_id'=>$id]),$this->audit(),$this->pdf);
        $this->assertSame($id,(int)$new->policy_type_id);
        $this->assertSame((int)$this->source->id,(int)$new->renewal_of_policy_id);
        $this->assertSame(1,DB::table('customers')->count());
    }
    public function test_missing_fields_incomplete_ocr_and_dob_conflicts_are_reported(): void
    {
        $audit=$this->audit(['holder_name'=>['value'=>null]]); $audit['data']['extraction_metadata']=['complete'=>false];
        $review=(new PolicyRenewalReview)->compare($this->source->load('holder'),$audit['data'],$this->input());
        $this->assertTrue($review['manual_required']); $this->assertNotEmpty($review['issues']);
        $review=(new PolicyRenewalReview)->compare($this->source,$this->audit(['holder_dob'=>['value'=>'1999-02-02']])['data'],$this->input());
        $this->assertNotEmpty($review['blocked']);
    }
    public function test_stale_source_and_wrong_source_audits_cannot_be_saved(): void
    {
        $audit=$this->audit(); $this->source->update(['premium_amount'=>'1200']);
        $this->expectException(ValidationException::class);
        (new AiPolicyRenewal)->save($this->source,$this->viewer,$this->input(),$audit,$this->pdf);
    }
    public function test_previous_document_is_not_accepted_as_a_renewal(): void
    {
        Storage::disk('public')->put('policies/old.pdf',file_get_contents($this->pdf)); $this->source->update(['policy_pdf'=>'old.pdf']);
        $this->expectException(ValidationException::class);
        (new AiPolicyRenewal)->save($this->source,$this->viewer,$this->input(),$this->audit(),$this->pdf);
    }
    public function test_read_only_user_cannot_renew(): void
    {
        $user=(new class extends User { public function isAdmin(): bool { return false; } public function hasMatrixPermission(string $permission): bool { return false; } })->forceFill(['id'=>10]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new AiPolicyRenewal)->save($this->source,$user,$this->input(),$this->audit(),$this->pdf);
    }
    public function test_multiple_policies_for_same_holder_keep_the_explicit_source_link(): void
    {
        $other = $this->source->replicate(); $other->policy_number='ANOTHER'; $other->policy_number_no='ANOTHER-456'; $other->save();
        $audit=$this->audit();$audit['renewal_source_id']=$other->id;$audit['renewal_source_fingerprint']=(new PolicyRenewalReview)->fingerprint($other);
        $new=(new AiPolicyRenewal)->save($other,$this->viewer,$this->input(['policy_number'=>'ANOTHER-456']),$audit,$this->pdf);
        $this->assertSame((int)$other->id,(int)$new->renewal_of_policy_id);
        $this->assertSame(0,$this->source->renewals()->count());
    }
    public function test_partial_failure_rolls_back_new_term_and_removes_its_pdf(): void
    {
        \App\Models\PolicyMember::creating(fn()=>throw new \RuntimeException('Simulated member failure'));
        try {
            (new AiPolicyRenewal)->save($this->source,$this->viewer,$this->input(),$this->audit(),$this->pdf);
            $this->fail('Failure not raised');
        } catch(\RuntimeException $e) {
            $this->assertSame('Simulated member failure',$e->getMessage());
            $this->assertSame(1,Policy::count());
            $this->assertSame(0,DB::table('policy_pdf_histories')->count());
            $this->assertSame(['renewal-test.pdf'],Storage::disk('local')->allFiles());
        } finally { \App\Models\PolicyMember::flushEventListeners(); }
    }
    public function test_staff_cannot_renew_another_users_policy(): void
    {
        $user=(new class extends User { public function isAdmin(): bool { return false; } public function hasMatrixPermission(string $permission): bool { return $permission === 'edit_policies'; } })->forceFill(['id'=>20]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new AiPolicyRenewal)->save($this->source,$user,$this->input(),$this->audit(),$this->pdf);
    }
    public function test_same_existing_period_is_not_a_renewal(): void
    {
        $this->expectException(ValidationException::class);
        (new AiPolicyRenewal)->save($this->source,$this->viewer,$this->input(['start_date'=>'2025-10-20','end_date'=>'2026-10-19']),$this->audit(),$this->pdf);
    }
    private function storeAudit(): string
    {
        $audit=$this->audit();$id=$audit['upload_id'];
        Storage::disk('local')->put("fablead_ai/pending/{$id}/metadata.json",json_encode(['user_id'=>10,'renewal_source_id'=>$this->source->id]));
        Storage::disk('local')->put("fablead_ai/extractions/{$id}.json",json_encode($audit));
        Storage::disk('local')->put("fablead_ai/pending/{$id}/policy.pdf",file_get_contents($this->pdf));
        return $id;
    }
    public function test_http_review_does_not_save_and_confirmed_save_links_new_term(): void
    {
        $input=$this->input()+['upload_id'=>$this->storeAudit()];
        $this->postJson(route('fablead.ai.renewal.review',$this->source),$input)->assertOk()->assertJsonPath('status',true)->assertJsonPath('review.blocked',[]);
        $this->assertSame(1,Policy::count());
        $response=$this->postJson(route('fablead.ai.renewal.save',$this->source),$input)->assertOk()->assertJsonPath('status',true);
        $this->assertSame((int)$this->source->id,(int)Policy::findOrFail($response->json('policy_id'))->renewal_of_policy_id);
    }
    public function test_http_save_requires_confirmation_and_upload_cannot_switch_to_another_source(): void
    {
        $input=$this->input(['renewal_confirmed'=>null])+['upload_id'=>$this->storeAudit()];
        $this->postJson(route('fablead.ai.renewal.save',$this->source),$input)->assertUnprocessable()->assertJsonValidationErrors('renewal_confirmed');
        $other=$this->source->replicate();$other->policy_number='OTHER';$other->save();
        $this->postJson(route('fablead.ai.renewal.review',$other),$input)->assertForbidden();
        $this->assertSame(2,Policy::count());
    }
    public function test_manual_renewal_also_preserves_original_term_and_covered_members(): void
    {
        $this->source->members()->create(['name'=>'Existing insured','relation'=>'Self']);
        $before=$this->source->fresh()->getAttributes();
        $response=$this->post(route('policies.legacy.renew_post',$this->source->id),[
            'start_date'=>'2026-10-20','end_date'=>'2027-10-19','company_id'=>1,'premium'=>'2000',
            'policy_pdf'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('renewed.pdf','%PDF-1.4 manual renewed policy'),
        ])->assertOk()->assertJsonPath('status',true);
        $new=Policy::findOrFail($response->json('new_policy_id'));
        $this->assertSame($before,$this->source->fresh()->getAttributes());
        $this->assertSame((int)$this->source->id,(int)$new->renewal_of_policy_id);
        $this->assertSame(1,$this->source->members()->count());$this->assertSame(1,$new->members()->count());
    }
    public function test_renewal_extraction_never_creates_a_customer_from_a_different_pdf_holder(): void
    {
        $id=$this->storeAudit();
        $pdf=\Barryvdh\DomPDF\Facade\Pdf::loadHTML('<p>Renewed insurance policy schedule. This readable test document contains enough text for the PDF parser to extract.</p>')->output();
        Storage::disk('local')->put("fablead_ai/pending/{$id}/policy.pdf",$pdf);
        $values=['insurance_company'=>'Old insurer','policy_type'=>'Health','agent_code'=>'AG1000','sum_assured'=>500000,'policy_number'=>'OLD-123','start_date'=>'2026-10-20','end_date'=>'2027-10-19','premium_amount'=>1500,'holder_name'=>'Different PDF Holder'];
        $fields=array_map(fn($value)=>['value'=>$value,'confidence'=>95,'source_text'=>'Test PDF schedule'],$values);
        $result=['fields'=>$fields,'policy_type_detected'=>'Health','covered_members'=>[]];
        $pipeline=\Mockery::mock(\App\Services\PolicyExtractionPipeline::class);
        $pipeline->shouldReceive('extract')->once()->andReturn(['data'=>$result,'chunks'=>[['result'=>$result]],'failed_chunks'=>[],'complete'=>true]);
        $this->app->instance(\App\Services\PolicyExtractionPipeline::class,$pipeline);
        $response=$this->postJson(route('fablead.ai.extract'),['upload_id'=>$id])->assertOk()->assertJsonPath('status',true);
        $this->assertEquals(1,$response->json('data.resolved.user_id'));
        $this->assertSame(1,DB::table('customers')->count());$this->assertSame(1,Policy::count());
    }
    public function test_optional_old_fields_can_be_explicitly_cleared_without_changing_history(): void
    {
        $this->source->update(['nominee_name'=>'Old nominee','vehicle_registration_number'=>'OLD-VEHICLE']);
        $input=$this->input(['clear_fields'=>['nominee_name','vehicle_reg_no']]);
        $new=(new AiPolicyRenewal)->save($this->source,$this->viewer,$input,$this->audit(),$this->pdf);
        $this->assertNull($new->nominee_name);$this->assertNull($new->vehicle_registration_number);
        $this->assertSame('Old nominee',$this->source->fresh()->nominee_name);
        $this->assertSame('OLD-VEHICLE',$this->source->fresh()->vehicle_registration_number);
    }
    public function test_vehicle_details_get_a_new_row_without_mutating_original_vehicle(): void
    {
        Schema::create('vehicle_policies',function(Blueprint $t) { $t->id();$t->integer('policy_id');$t->string('vehicle_reg_no')->nullable();$t->string('engine_no')->nullable();$t->timestamps(); });
        $vehicle=\App\Models\VehiclePolicyModel::create(['policy_id'=>$this->source->id,'vehicle_reg_no'=>'OLDREG','engine_no'=>'OLDENGINE']);
        $this->source->update(['policy_type_id'=>3]);
        $new=(new AiPolicyRenewal)->save($this->source,$this->viewer,$this->input(['policy_type_id'=>3,'vehicle_reg_no'=>'NEWREG','engine_no'=>'NEWENGINE']),$this->audit(),$this->pdf);
        $this->assertSame('OLDREG',$vehicle->fresh()->vehicle_reg_no);
        $copy=\App\Models\VehiclePolicyModel::where('policy_id',$new->id)->firstOrFail();
        $this->assertSame('NEWREG',$copy->vehicle_reg_no);$this->assertSame('NEWENGINE',$copy->engine_no);
    }
}
