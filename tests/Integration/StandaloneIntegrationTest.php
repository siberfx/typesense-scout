<?php

namespace Siberfx\Typesense\Tests\Integration;

use Siberfx\Typesense\Standalone\TypesenseConnection;
use Siberfx\Typesense\Standalone\TypesenseManager;

uses(IntegrationServer::class);

beforeEach(function () {
    $this->connectToIntegrationServer();
});
trait StandaloneIntegrationTestHelpers
{
    protected function manager(): TypesenseManager
    {
        return TypesenseManager::fromConfig([
            'default' => 'default',
            'connections' => [
                'default' => [
                    'api_key' => getenv('TYPESENSE_API_KEY') ?: 'xyz',
                    'nodes' => [[
                        'host' => getenv('TYPESENSE_HOST') ?: 'localhost',
                        'port' => getenv('TYPESENSE_PORT') ?: '8108',
                        'protocol' => getenv('TYPESENSE_PROTOCOL') ?: 'http',
                    ]],
                    'connection_timeout_seconds' => 2,
                ],
            ],
        ]);
    }
}

uses(StandaloneIntegrationTestHelpers::class);

test('full document lifecycle', function () {
    $manager = $this->manager();
    $connection = $manager->connection();
    expect($connection)->toBeInstanceOf(TypesenseConnection::class);

    $collection = 'standalone_books';
    $this->dropCollection($collection);

    // create (idempotent)
    $connection->ensureCollection([
        'name' => $collection,
        'fields' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'year', 'type' => 'int32'],
        ],
        'default_sorting_field' => 'year',
    ]);
    expect($connection->hasCollection($collection))->toBeTrue();

    // bulk import
    $results = $connection->documents($collection)->import([
        ['id' => '1', 'title' => 'Dune', 'year' => 1965],
        ['id' => '2', 'title' => 'Neuromancer', 'year' => 1984],
    ], 'upsert');
    expect($results)->toHaveCount(2);
    expect($results[0]['success'])->toBeTrue();

    // single search
    $hits = $connection->search($collection, ['q' => 'dune', 'query_by' => 'title']);
    expect($hits['found'])->toBe(1);

    // federated multi-search
    $multi = $connection->multiSearch([
        ['collection' => $collection, 'q' => 'dune', 'query_by' => 'title'],
        ['collection' => $collection, 'q' => 'neuromancer', 'query_by' => 'title'],
    ]);
    expect($multi['results'])->toHaveCount(2);

    // delete by filter + drop
    $connection->documents($collection)->deleteByFilter(['filter_by' => 'year:<1970']);
    $after = $connection->search($collection, ['q' => '*', 'query_by' => 'title']);
    expect($after['found'])->toBe(1);

    $connection->dropCollection($collection);
    expect($connection->hasCollection($collection))->toBeFalse();
});

test('reindex with chunked import, filter update, truncate and alias swap', function () {
    $connection = $this->manager()->connection();
    $schema = fn (string $name) => [
        'name' => $name,
        'fields' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'year', 'type' => 'int32'],
            ['name' => 'classic', 'type' => 'bool', 'optional' => true],
        ],
    ];

    foreach (['standalone_reindex_v1', 'standalone_reindex_v2'] as $name) {
        $this->dropCollection($name);
    }
    try {
        $connection->aliases()['standalone_reindex']->delete();
    } catch (\Throwable) {
        // Alias did not exist.
    }

    $connection->createCollection($schema('standalone_reindex_v1'));
    expect($connection->swapAlias('standalone_reindex', 'standalone_reindex_v1'))->toBeNull();
    expect($connection->aliasTarget('standalone_reindex'))->toBe('standalone_reindex_v1');

    // Chunked import from a generator, with one bad row.
    $connection->createCollection($schema('standalone_reindex_v2'));
    $docs = (function () {
        yield ['id' => '1', 'title' => 'Dune', 'year' => 1965];
        yield ['id' => '2', 'title' => 'Neuromancer', 'year' => 1984];
        yield ['id' => '3', 'title' => 'Foundation', 'year' => 'not a year'];
    })();
    $result = $connection->documents('standalone_reindex_v2')->importChunked($docs, 'upsert', 2);
    expect($result['imported'])->toBe(2);
    expect($result['failed'])->toBe(1);
    expect($result['errors'][0]['row'])->toBe(2);

    // Update by filter.
    expect($connection->documents('standalone_reindex_v2')->updateByFilter(['classic' => true], 'year:<1970'))->toBe(1);

    // Swap and drop the old collection.
    expect($connection->swapAlias('standalone_reindex', 'standalone_reindex_v2', dropPrevious: true))->toBe('standalone_reindex_v1');
    expect($connection->hasCollection('standalone_reindex_v1'))->toBeFalse();
    $hits = $connection->search('standalone_reindex', ['q' => '*', 'query_by' => 'title', 'filter_by' => 'classic:true']);
    expect($hits['found'])->toBe(1);

    // Truncate keeps the schema.
    expect($connection->truncateCollection('standalone_reindex_v2'))->toBe(2);
    expect($connection->hasCollection('standalone_reindex_v2'))->toBeTrue();

    $connection->aliases()['standalone_reindex']->delete();
    $connection->dropCollection('standalone_reindex_v2');
});
