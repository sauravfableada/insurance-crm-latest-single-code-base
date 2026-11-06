<?php

namespace Tests\Unit;

use App\Services\{PolicyTextFieldExtractor, PolicyFieldSuggestions};
use PHPUnit\Framework\TestCase;

class PolicySumInsuredExtractorTest extends TestCase
{
    public function test_base_sum_insured_under_split_table_headers_is_populated(): void
    {
        $text = "Insured Person's Details\nBase Sum Insured\nInitial Waiting Period\nSpecific disease Waiting period\nPre-Existing Disease Waiting Period\nSuper Credit Amount\nSuper Credit %\n1000000\n30 Days\n2 Years\n3 Years\n0\nNA";
        $result = (new PolicyTextFieldExtractor)->enrich(['fields' => []], $text);
        $this->assertSame('1000000', $result['fields']['sum_assured']['value']);
    }

    public function test_previous_portability_amounts_do_not_replace_current_cover(): void
    {
        $text = "Portability Details (If Applicable)\nPrevious Insurer\nSum Insured: 1540000\nSum Insured: 1120000\nSum Insured: 700000\nSum Insured: 500000\nWaiting Period Details\nBase Sum Insured: 2000000";
        $result = (new PolicyTextFieldExtractor)->enrich(['fields' => ['sum_assured' => ['value' => 1540000, 'confidence' => 100]]], $text);
        $this->assertSame('2000000', $result['fields']['sum_assured']['value']);
        $this->assertCount(1, $result['field_candidates']['sum_assured']);
    }

    public function test_addon_limits_are_not_main_motor_cover(): void
    {
        $text = "Total IDV: Rs. 6,50,000\nAdd on Covers: keys and locks replacement cover with sum insured Rs.15000, personal baggage cover with sum insured Rs.30000, accidental medical expenses cover with sum insured Rs.100000.00";
        $result = (new PolicyTextFieldExtractor)->enrich(['fields' => ['sum_assured' => ['value' => 15000, 'confidence' => 99]]], $text);
        $this->assertSame('650000', $result['fields']['sum_assured']['value']);
        $this->assertCount(1, $result['field_candidates']['sum_assured']);
    }

    public function test_addon_or_historical_only_text_does_not_autofill_main_cover(): void
    {
        foreach (["Personal baggage cover with sum insured Rs.30000", "Portability Details\nPrevious Policy\nSum Insured: 1540000"] as $text) {
            $result = (new PolicyTextFieldExtractor)->enrich(['fields' => []], $text);
            $this->assertEmpty($result['fields']['sum_assured']['value'] ?? null);
        }
        $result = (new PolicyTextFieldExtractor)->enrich(['fields' => ['sum_assured' => ['value' => 1540000, 'confidence' => 100, 'source_text' => 'Sum Insured: 1540000']]], "Portability Details\nPrevious Policy\nSum Insured: 1540000");
        $result = (new PolicyFieldSuggestions)->enrich($result, '', [['result' => ['fields' => ['sum_assured' => ['value' => 1540000, 'confidence' => 100]]]]], [], []);
        $this->assertNull($result['fields']['sum_assured']['value']);
    }

    public function test_conflicting_current_member_limits_require_review_instead_of_adding_them(): void
    {
        $result = (new PolicyTextFieldExtractor)->enrich(['fields' => []], "Base Sum Insured: 500000\nBase Sum Insured: 1000000");
        $this->assertNull($result['fields']['sum_assured']['value']);
        $this->assertTrue($result['fields']['sum_assured']['review_required']);
        $this->assertCount(2, $result['field_candidates']['sum_assured']);
    }

    public function test_correct_amount_and_other_fields_survive_suggestion_enrichment(): void
    {
        $result = (new PolicyTextFieldExtractor)->enrich(['fields' => ['holder_name' => ['value' => 'Test Holder', 'confidence' => 100], 'premium_amount' => ['value' => 1234, 'confidence' => 100]]], 'Base Sum Insured: ₹10 Lakh');
        $result = (new PolicyFieldSuggestions)->enrich($result, '', [['result' => ['fields' => ['sum_assured' => ['value' => 500000, 'confidence' => 95, 'source_text' => 'Previous policy sum insured']]]]], [], []);
        $this->assertSame('1000000', $result['fields']['sum_assured']['value']);
        $this->assertArrayNotHasKey('sum_assured', $result['suggestions']);
        $this->assertSame('Test Holder', $result['fields']['holder_name']['value']);
        $this->assertSame(1234, $result['fields']['premium_amount']['value']);
    }
}
