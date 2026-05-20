<?php

namespace Tests\Unit;

use App\Support\JsonPayloadCleaner;
use PHPUnit\Framework\TestCase;

class JsonPayloadCleanerTest extends TestCase
{
    public function test_it_cleans_common_ai_json_formatting_issues(): void
    {
        $dirtyPayload = "\xEF\xBB\xBF```json\r\n{\r\n  \"product_family_name\": \"Ultra\u{200B} Braid\",\r\n  \"notes\": \"Line 1\nLine 2\",\r\n  \"category\": \"Hair\"\x01\r\n}\r\n```";

        $result = (new JsonPayloadCleaner())->clean($dirtyPayload);
        $decoded = json_decode($result['cleaned_payload'], true);

        $this->assertTrue($result['changed']);
        $this->assertIsArray($decoded);
        $this->assertSame('Ultra Braid', $decoded['product_family_name']);
        $this->assertSame("Line 1\nLine 2", $decoded['notes']);
        $this->assertNotEmpty($result['cleanup_notes']);
    }
}
