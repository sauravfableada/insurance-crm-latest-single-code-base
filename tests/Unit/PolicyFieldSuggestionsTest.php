<?php

namespace Tests\Unit;

use App\Services\PolicyFieldSuggestions;
use PHPUnit\Framework\TestCase;

class PolicyFieldSuggestionsTest extends TestCase
{
    public function test_digit_employee_compensation_title_overrides_wrong_type_without_changing_other_fields(): void
    {
        $data = (new PolicyFieldSuggestions)->enrich([
            'policy_type_detected' => 'Health',
            'fields' => ['policy_type' => ['value' => 'Health', 'confidence' => 95],
                'policy_number' => ['value' => 'D123', 'confidence' => 99],
                'premium_amount' => ['value' => 4954.02, 'confidence' => 99]],
        ], 'Digit Employees Compensation Insurance Policy. Medical Expenses Cover. Fire cover exclusions.', [], [],
            [['id' => 11, 'name' => 'Workmen Compensation (WC)'], ['id' => 12, 'name' => 'Health']]);
        $this->assertSame(11, $data['resolved']['policy_type_id']);
        $this->assertSame('Workmen Compensation (WC)', $data['fields']['policy_type']['value']);
        $this->assertCount(1, $data['suggestions']['policy_type']);
        $this->assertSame('D123', $data['fields']['policy_number']['value']);
        $this->assertSame(4954.02, $data['fields']['premium_amount']['value']);
    }

    public function test_workmen_compensation_aliases_match_the_same_master_type(): void
    {
        $service = new PolicyFieldSuggestions;
        foreach (['WC', 'WC Policy', "Workmen’s Compensation", 'Workers Compensation Insurance', 'Employee Compensation', 'Employees Compensation Insurance Policy'] as $alias) {
            $matches = $service->matchCatalog($alias, [['id' => 11, 'name' => 'Workmen Compensation (WC)']]);
            $this->assertTrue($matches[0]['exact'] ?? false, $alias);
        }
        foreach (['WC Policy', 'Workmen Compensation Policy', 'Digit Employees Compensation Insurance Policy'] as $title) {
            $this->assertNotNull(\App\Services\WorkmenCompensationType::evidence($title), $title);
        }
        $this->assertNull(\App\Services\WorkmenCompensationType::evidence('Personal Accident Policy. Exclusion under Workmen Compensation Act 1923.'));
    }

    public function test_company_suffixes_match_without_confusing_different_insurers(): void
    {
        $service = new PolicyFieldSuggestions;
        $catalog = [['id' => 1, 'name' => 'Aditya Birla Health Insurance Co Ltd'], ['id' => 2, 'name' => 'Axis Max Life Insurance Co Ltd']];
        $data = $service->enrich(['fields' => ['insurance_company' => ['value' => 'Aditya Birla Health Insurance Co. Limited', 'confidence' => 100]]], '', [], $catalog, []);
        $this->assertSame(1, $data['resolved']['company_id']);
        $this->assertSame('Aditya Birla Health Insurance Co Ltd', $data['resolved']['company_name']);
        $this->assertCount(1, $data['suggestions']['insurance_company']);
    }

    public function test_family_floater_uses_health_category_and_preserves_subtype(): void
    {
        $data = (new PolicyFieldSuggestions)->enrich(['policy_type_detected' => 'Health', 'fields' => [
            'policy_type' => ['value' => 'Family Floater', 'confidence' => 100, 'source_text' => 'Policy Type Family Floater'],
        ]], '', [], [], [['id' => 4, 'name' => 'HEALTH INSURANCE'], ['id' => 5, 'name' => 'LIFE INSURANCE']]);
        $this->assertSame(4, $data['resolved']['policy_type_id']);
        $this->assertSame('Family Floater', $data['fields']['policy_sub_type']['value']);
        $this->assertSame('Health', $data['fields']['policy_type']['value']);
    }

    public function test_unknown_company_is_preserved_as_addable_suggestion(): void
    {
        $data = (new PolicyFieldSuggestions)->enrich(['fields' => ['insurance_company' => ['value' => 'Unlisted Health Insurer', 'confidence' => 100]]], '', [], [['id' => 1, 'name' => 'Axis Max Life Insurance']], []);
        $this->assertNull($data['resolved']['company_id']);
        $this->assertTrue($data['suggestions']['insurance_company'][0]['requires_creation']);
        $this->assertTrue($data['fields']['insurance_company']['review_required']);
    }

    public function test_multiline_intermediary_code_is_recovered_from_labelled_source(): void
    {
        $data = (new PolicyFieldSuggestions)->enrich(['fields' => ['agent_code' => ['value' => null]]], "Intermediary\nCode \nABH1141976\nIntermediary Contact Details", [], [], []);
        $this->assertSame('ABH1141976', $data['fields']['agent_code']['value']);
        $this->assertStringContainsString('Intermediary', $data['fields']['agent_code']['source_text']);
    }

    public function test_conflicting_amounts_remain_selectable_and_only_important_fields_get_suggestions(): void
    {
        $data = (new PolicyFieldSuggestions)->enrich(['fields' => ['sum_assured' => ['value' => 500000, 'confidence' => 95]]], '', [
            ['result' => ['field_candidates' => ['sum_assured' => [['value' => 1000000, 'source_text' => 'Sum insured 1000000']], 'room_rent_limit' => [['value' => 'Unlimited']]]]],
        ], [], []);
        $this->assertCount(2, $data['suggestions']['sum_assured']);
        $this->assertTrue($data['fields']['sum_assured']['review_required']);
        $this->assertArrayNotHasKey('room_rent_limit', $data['suggestions']);
        $this->assertContains('sum_assured', $data['needs_review_fields']);
    }

    public function test_catalogue_and_motor_wording_are_recovered_from_raw_text_without_ai_fields(): void
    {
        $data = (new PolicyFieldSuggestions)->enrich(
            ['fields' => []],
            'Bajaj General Insurance Limited Transcript of Proposal for Private Car Package Policy',
            [],
            [['id' => 7, 'name' => 'Bajaj General Insurance Co Ltd']],
            [['id' => 9, 'name' => 'Motor']]
        );

        $this->assertSame(7, $data['resolved']['company_id']);
        $this->assertSame(9, $data['resolved']['policy_type_id']);
    }

    public function test_deterministic_phone_candidates_reach_the_review_suggestions(): void
    {
        $data = (new PolicyFieldSuggestions)->enrich([
            'fields' => [],
            'field_candidates' => ['customer_phone' => [
                ['value' => '9228262343', 'confidence' => 70, 'source_text' => '9228262343'],
                ['value' => '7228866466', 'confidence' => 70, 'source_text' => '7228866466'],
            ]],
        ], '', [], [], []);

        $this->assertCount(2, $data['suggestions']['customer_phone']);
        $this->assertContains('customer_phone', $data['needs_review_fields']);
    }
}
