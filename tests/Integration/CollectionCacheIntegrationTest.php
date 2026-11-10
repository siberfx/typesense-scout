<?php

namespace Siberfx\Typesense\Tests\Integration;

uses(IntegrationServer::class);

beforeEach(function () {
    $this->connectToIntegrationServer();
});
/**
 * The collection handle cache against a real server: a cached collection that
 * was dropped by another process is recreated on the next operation.
 */

trait CollectionCacheIntegrationTestHelpers
{
    protected const COLLECTION = 'integration_collection_cache';

    protected function model(): object
    {
        // The anonymous class cannot read the test's private constant, so the name is passed in.
        return new class(self::COLLECTION) {
            public function __construct(private readonly string $collection)
            {
            }

            public function searchableAs(): string
            {
                return $this->collection;
            }

            public function getCollectionSchema(): array
            {
                return [
                    'name' => $this->collection,
                    'fields' => [['name' => 'title', 'type' => 'string']],
                ];
            }
        };
    }
}

uses(CollectionCacheIntegrationTestHelpers::class);

afterEach(function () {
    if (isset($this->client)) {
        $this->dropCollection(self::COLLECTION);
    }
});

test('search recreates a collection dropped after it was cached', function () {
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

    expect($result['found'])->toBe(0);
    expect($this->client->getCollections()[self::COLLECTION]->retrieve())->not->toBeEmpty();
});

test('delete collection forgets the cached handle', function () {
    $model = $this->model();
    $this->typesense->getCollectionIndex($model);

    $this->typesense->deleteCollection(self::COLLECTION);

    // A fresh lookup must create the collection again rather than reuse the handle.
    $collection = $this->typesense->getCollectionIndex($model);
    expect($collection->retrieve())->not->toBeEmpty();
});
