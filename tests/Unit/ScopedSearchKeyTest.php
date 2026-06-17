<?php

namespace Siberfx\Typesense\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Typesense;
use Typesense\Client;

/**
 * Tests real Typesense scoped search key generation. The key is computed
 * locally via HMAC (no network call), so a client pointed at a dummy node is
 * sufficient.
 */
class ScopedSearchKeyTest extends TestCase
{
    private function typesense(): Typesense
    {
        $client = new Client([
            'api_key' => 'parent-search-key',
            'nodes' => [
                ['host' => 'localhost', 'port' => '8108', 'protocol' => 'http'],
            ],
            'connection_timeout_seconds' => 2,
        ]);

        return new Typesense($client);
    }

    public function test_generates_a_deterministic_scoped_key_embedding_parameters(): void
    {
        $params = ['filter_by' => 'company_id:42'];

        $scoped = $this->typesense()->generateScopedSearchKey('parent-search-key', $params);

        $this->assertNotEmpty($scoped);

        // The scoped key is base64; once decoded it must embed the JSON params.
        $decoded = base64_decode($scoped);
        $this->assertStringContainsString('filter_by', $decoded);
        $this->assertStringContainsString('company_id:42', $decoded);

        // Same inputs must always yield the same key.
        $this->assertSame(
            $scoped,
            $this->typesense()->generateScopedSearchKey('parent-search-key', $params)
        );
    }

    public function test_different_parameters_produce_different_keys(): void
    {
        $a = $this->typesense()->generateScopedSearchKey('parent-search-key', ['filter_by' => 'company_id:1']);
        $b = $this->typesense()->generateScopedSearchKey('parent-search-key', ['filter_by' => 'company_id:2']);

        $this->assertNotSame($a, $b);
    }
}
