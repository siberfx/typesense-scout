<?php

namespace Siberfx\Typesense\Tests\Unit;

use Siberfx\Typesense\Typesense;
use Typesense\Client;

/**
 * Tests real Typesense scoped search key generation. The key is computed
 * locally via HMAC (no network call), so a client pointed at a dummy node is
 * sufficient.
 */

trait ScopedSearchKeyTestHelpers
{
    protected function typesense(): Typesense
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
}

uses(ScopedSearchKeyTestHelpers::class);

test('generates a deterministic scoped key embedding parameters', function () {
    $params = ['filter_by' => 'company_id:42'];

    $scoped = $this->typesense()->generateScopedSearchKey('parent-search-key', $params);

    expect($scoped)->not->toBeEmpty();

    // The scoped key is base64; once decoded it must embed the JSON params.
    $decoded = base64_decode($scoped);
    expect($decoded)->toContain('filter_by');
    expect($decoded)->toContain('company_id:42');

    // Same inputs must always yield the same key.
    expect($this->typesense()->generateScopedSearchKey('parent-search-key', $params))->toBe($scoped);
});

test('different parameters produce different keys', function () {
    $a = $this->typesense()->generateScopedSearchKey('parent-search-key', ['filter_by' => 'company_id:1']);
    $b = $this->typesense()->generateScopedSearchKey('parent-search-key', ['filter_by' => 'company_id:2']);

    expect($b)->not->toBe($a);
});
