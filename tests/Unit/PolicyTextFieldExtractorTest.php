<?php

namespace Tests\Unit;

use App\Services\PolicyTextFieldExtractor;
use PHPUnit\Framework\TestCase;

class PolicyTextFieldExtractorTest extends TestCase
{
    public function test_extracts_bajaj_transcript_policy_number_and_proposer(): void
    {
        $text = <<<'TEXT'
Bajaj General Insurance Limited
Dear MOHANBHAI BHIKHABHAI PATEL,
We wish to inform you that the contract under policy number 'OG-26-2203-1801-00016602' has been finalized.
A. Proposer details
1. Proposer Name : MOHANBHAI BHIKHABHAI PATEL
2. Proposer Address : 600 NAVU FALIYU PUNAGAM
TEXT;

        $data = (new PolicyTextFieldExtractor)->enrich(['fields' => []], $text);

        $this->assertSame('OG-26-2203-1801-00016602', $data['fields']['policy_number']['value']);
        $this->assertSame('MOHANBHAI BHIKHABHAI PATEL', $data['fields']['holder_name']['value']);
        $this->assertSame('label_fallback', $data['fields']['policy_number']['extraction_method']);
        $this->assertNotEmpty($data['field_candidates']['policy_number']);
    }

    public function test_does_not_replace_a_stronger_ai_value(): void
    {
        $input = ['fields' => ['policy_number' => ['value' => 'AI-123456', 'confidence' => 99, 'source_text' => 'AI evidence']]];
        $data = (new PolicyTextFieldExtractor)->enrich($input, 'Policy Number: PDF-987654');

        $this->assertSame('AI-123456', $data['fields']['policy_number']['value']);
        $this->assertSame('PDF-987654', $data['field_candidates']['policy_number'][0]['value']);
    }

    public function test_normalizes_labelled_dates_and_amounts(): void
    {
        $data = (new PolicyTextFieldExtractor)->enrich(['fields' => []], "Policy Start Date: 20/10/2026\nExpiry Date: 19/10/2027\nTotal Premium: Rs. 28,594.00");

        $this->assertSame('2026-10-20', $data['fields']['start_date']['value']);
        $this->assertSame('2027-10-19', $data['fields']['end_date']['value']);
        $this->assertSame('28594.00', $data['fields']['premium_amount']['value']);
    }

    public function test_recovers_phone_broker_nominee_and_lakh_sum_insured(): void
    {
        $text = <<<'TEXT'
Policy Holder Mobile Number: 9228262343
Intermediary Name: Yogesh Shah
Intermediary Contact Number: 7228866466
Nominee Name: Sheetal Shah
Nominee Mobile No: 9228292353
Base Sum Insured: Rs. 7 Lac
TEXT;
        $data = (new PolicyTextFieldExtractor)->enrich(['fields' => [
            'customer_phone' => ['value' => 'XXXXX XXXXX', 'confidence' => 90],
        ]], $text);

        $this->assertSame('9228262343', $data['fields']['customer_phone']['value']);
        $this->assertSame('Yogesh Shah', $data['fields']['broker_name']['value']);
        $this->assertSame('7228866466', $data['fields']['broker_contact']['value']);
        $this->assertSame('Sheetal Shah', $data['fields']['nominee_name']['value']);
        $this->assertSame('9228292353', $data['fields']['nominee_contact']['value']);
        $this->assertSame('700000', $data['fields']['sum_assured']['value']);
    }

    public function test_unique_standalone_phone_is_used_but_multiple_numbers_remain_candidates(): void
    {
        $single = (new PolicyTextFieldExtractor)->enrich(['fields' => []], "Kamlesh Shah\n9228262343\n20/10/2026");
        $this->assertSame('9228262343', $single['fields']['customer_phone']['value']);

        $multiple = (new PolicyTextFieldExtractor)->enrich(['fields' => []], "9228262343\n7228866466");
        $this->assertArrayNotHasKey('customer_phone', $multiple['fields']);
        $this->assertCount(2, $multiple['field_candidates']['customer_phone']);
    }
}
