<?php

namespace Siberfx\Typesense\Tests\Integration;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Siberfx\Typesense\Engines\TypesenseEngine;

uses(IntegrationServer::class);

/**
 * Scout engine index maintenance against a real server: removing models and
 * flushing collections.
 */

trait EngineLifecycleIntegrationTestHelpers
{
    protected const COLLECTION = 'integration_engine_lifecycle';

    protected function model(int|string $key = 0): object
    {
        return new class($key, self::COLLECTION) {
            public function __construct(private int|string $key, private string $collection)
            {
            }

            public function getScoutKey(): int|string
            {
                return $this->key;
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

            public function typesenseQueryBy(): array
            {
                return ['title'];
            }
        };
    }

    /**
     * @param array<int, string> $ids
     */
    protected function seed(array $ids): void
    {
        $this->dropCollection(self::COLLECTION);
        $this->client->getCollections()->create($this->model()->getCollectionSchema());

        $documents = $this->client->getCollections()[self::COLLECTION]->getDocuments();
        foreach ($ids as $id) {
            $documents->create(['id' => $id, 'title' => 'Document ' . $id]);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function indexedIds(): array
    {
        $result = $this->client->getCollections()[self::COLLECTION]->getDocuments()
            ->search(['q' => '*', 'query_by' => 'title', 'per_page' => 250, 'sort_by' => '_seq_id:asc']);

        return array_map(static fn (array $hit) => $hit['document']['id'], $result['hits']);
    }
}

uses(EngineLifecycleIntegrationTestHelpers::class);

beforeEach(function () {
    $this->connectToIntegrationServer();
});

afterEach(function () {
    if (isset($this->client)) {
        $this->dropCollection(self::COLLECTION);
    }
});

test('delete removes only the given documents', function () {
    $this->seed(['1', '2', '3', 'a,b', 'x y']);

    (new TypesenseEngine($this->typesense))->delete(new EloquentCollection([
        $this->model(1),
        $this->model(3),
        $this->model('a,b'),
        $this->model('not-indexed'),
    ]));

    expect($this->indexedIds())->toBe(['2', 'x y']);
});

test('delete handles more ids than one batch', function () {
    $ids = array_map('strval', range(1, TypesenseEngine::DELETE_BATCH_SIZE + 20));
    $this->seed($ids);

    $models = array_map(fn (string $id) => $this->model($id), array_slice($ids, 0, -1));

    (new TypesenseEngine($this->typesense))->delete(new EloquentCollection($models));

    expect($this->indexedIds())->toBe([(string) (TypesenseEngine::DELETE_BATCH_SIZE + 20)]);
});

test('flush drops the collection', function () {
    $this->seed(['1']);

    (new TypesenseEngine($this->typesense))->flush($this->model());

    expect(fn () => $this->client->getCollections()[self::COLLECTION]->retrieve())
        ->toThrow(\Typesense\Exceptions\ObjectNotFound::class);
});

test('flush of a missing collection does not create it', function () {
    $this->dropCollection(self::COLLECTION);

    (new TypesenseEngine($this->typesense))->flush($this->model());

    expect(fn () => $this->client->getCollections()[self::COLLECTION]->retrieve())
        ->toThrow(\Typesense\Exceptions\ObjectNotFound::class);
});
