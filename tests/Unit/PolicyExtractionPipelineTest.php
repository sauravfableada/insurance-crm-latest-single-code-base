<?php

namespace Tests\Unit;

use App\Services\PolicyExtractionPipeline;
use PHPUnit\Framework\TestCase;

class PolicyExtractionPipelineTest extends TestCase
{
    public function test_entire_document_including_last_page_is_sent_and_results_merge(): void
    {
        $text = str_repeat('A', 25000).'LAST_PAGE_VALUE';
        $received = [];
        $report = (new PolicyExtractionPipeline)->extract($text, function ($chunk) use (&$received) {
            $received[] = $chunk;
            return ['fields' => ['policy_number' => ['value' => str_contains($chunk, 'LAST_PAGE_VALUE') ? 'LAST-123' : null, 'confidence' => 90]]];
        });
        $this->assertCount(3, $received);
        $this->assertStringContainsString('LAST_PAGE_VALUE', $received[2]);
        $this->assertSame('LAST-123', $report['data']['fields']['policy_number']['value']);
        $this->assertTrue($report['complete']);
    }

    public function test_conflicts_and_unmapped_fields_are_preserved_without_null_overwriting_values(): void
    {
        $index = 0;
        $report = (new PolicyExtractionPipeline)->extract(str_repeat('x', 24001), function () use (&$index) {
            $values = [['Current', 95], ['Previous', 70], [null, 0]];
            [$value, $confidence] = $values[$index++];
            return ['fields' => ['policy_number' => compact('value', 'confidence')],
                'covered_members' => [['name' => 'Test Person', 'dob' => '2000-01-01', 'relation' => 'Self']],
                'additional_fields' => [['label' => 'Special condition', 'value' => 'Recorded condition']]];
        });
        $this->assertSame('Current', $report['data']['fields']['policy_number']['value']);
        $this->assertNotEmpty($report['data']['conflicts']);
        $this->assertCount(1, $report['data']['covered_members']);
        $this->assertCount(1, $report['data']['additional_fields']);
        $this->assertCount(3, $report['chunks']);
    }

    public function test_failed_chunks_are_reported_as_incomplete(): void
    {
        $report = (new PolicyExtractionPipeline)->extract('Some policy text', fn () => null);
        $this->assertFalse($report['complete']);
        $this->assertSame([1], $report['failed_chunks']);
        $this->assertEmpty($report['data']['fields']);
    }
}
