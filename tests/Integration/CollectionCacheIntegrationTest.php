<?php

namespace Siberfx\Typesense\Tests\Integration;

/**
 * The collection handle cache against a real server: a cached collection that
 * was dropped by another process is recreated on the next operation.
 */
class CollectionCacheIntegrationTest extends IntegrationTestCase
{
    private const COLLECTION = 'integration_collection_cache';

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $this->dropCollection(self::COLLECTION);
        }

        parent::tearDown();
    }

    private function model(): object
    {
        return new class {
            public function searchableAs(): string
            {
                return CollectionCacheIntegrationTest::COLLECTION;
            }

            public function getCollectionSchema(): array
            {
                return [
                    'name' => CollectionCacheIntegrationTest::COLLECTION,
                    'fields' => [['name' => 'title', 'type' => 'string']],
                ];
            }
        };
    }

    public function test_search_recreates_a_collection_dropped_after_it_was_cached(): void
    {
        $model = $this->model();
        $search = static fn ($collection) => $collection->getDocuments()->search([
            'q' => '*',
            'query_by' => 'title',
        ]);

        // First operation caches the handle and creates the collection.
        $this->typesense->withCollection($model, $search);

        // Another process drops the collection; this process still holds the handle.
        $this->dropCollection(self::COLLECTION);

        $result = $this->typesense->withCollection($model, $search);

        $this->assertSame(0, $result['found']);
        $this->assertNotEmpty($this->client->getCollections()[self::COLLECTION]->retrieve());
    }

    public function test_delete_collection_forgets_the_cached_handle(): void
    {
        $model = $this->model();
        $this->typesense->getCollectionIndex($model);

        $this->typesense->deleteCollection(self::COLLECTION);

        // A fresh lookup must create the collection again rather than reuse the handle.
        $collection = $this->typesense->getCollectionIndex($model);
        $this->assertNotEmpty($collection->retrieve());
    }
}
