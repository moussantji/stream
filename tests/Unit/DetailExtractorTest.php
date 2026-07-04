<?php

namespace Tests\Unit;

use App\Services\MovieBox\DetailExtractor;
use PHPUnit\Framework\TestCase;

class DetailExtractorTest extends TestCase
{
    public function test_it_resolves_the_index_referenced_nuxt_payload(): void
    {
        // Simulates the flattened / index-referenced payload MovieBox embeds:
        // - entry 0 -> {state: 1}
        // - entry 1 -> [2, 3]  (state list; state[1] resolves to entry 3)
        // - entry 3 -> the target object with 2-char prefixed keys
        $payload = json_encode([
            ['state' => 1],
            [2, 3],
            'ignored',
            ['$smovieTitle' => 4, '$sfoo' => 5],
            'Titanic',
            'bar',
        ]);

        $html = '<html><body><script type="application/json">'.$payload.'</script></body></html>';

        $result = DetailExtractor::extract($html);

        $this->assertIsArray($result);
        $this->assertSame('Titanic', $result['movieTitle']);
        $this->assertSame('bar', $result['foo']);
    }

    public function test_it_returns_null_when_no_json_script_is_present(): void
    {
        $this->assertNull(DetailExtractor::extract('<html><body>no data here</body></html>'));
    }

    public function test_it_returns_null_for_malformed_json(): void
    {
        $html = '<script type="application/json">{not valid json}</script>';

        $this->assertNull(DetailExtractor::extract($html));
    }
}
