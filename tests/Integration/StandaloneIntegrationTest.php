<?php

namespace Siberfx\Typesense\Tests\Integration;

use Siberfx\Typesense\Standalone\TypesenseConnection;
use Siberfx\Typesense\Standalone\TypesenseManager;

class StandaloneIntegrationTest extends IntegrationTestCase
{
    private function manager(): TypesenseManager
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

    public function test_full_document_lifecycle(): void
    {
        $manager = $this->manager();
        $connection = $manager->connection();
        $this->assertInstanceOf(TypesenseConnection::class, $connection);

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
        $this->assertTrue($connection->hasCollection($collection));

        // bulk import
        $results = $connection->documents($collection)->import([
            ['id' => '1', 'title' => 'Dune', 'year' => 1965],
            ['id' => '2', 'title' => 'Neuromancer', 'year' => 1984],
        ], 'upsert');
        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['success']);

        // single search
        $hits = $connection->search($collection, ['q' => 'dune', 'query_by' => 'title']);
        $this->assertSame(1, $hits['found']);

        // federated multi-search
        $multi = $connection->multiSearch([
            ['collection' => $collection, 'q' => 'dune', 'query_by' => 'title'],
            ['collection' => $collection, 'q' => 'neuromancer', 'query_by' => 'title'],
        ]);
        $this->assertCount(2, $multi['results']);

        // delete by filter + drop
        $connection->documents($collection)->deleteByFilter(['filter_by' => 'year:<1970']);
        $after = $connection->search($collection, ['q' => '*', 'query_by' => 'title']);
        $this->assertSame(1, $after['found']);

        $connection->dropCollection($collection);
        $this->assertFalse($connection->hasCollection($collection));
    }
}
