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
