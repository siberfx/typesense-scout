<?php

namespace Siberfx\Typesense\Tests\Integration;

use Laravel\Scout\Builder;
use Siberfx\Typesense\Engines\TypesenseEngine;

uses(IntegrationServer::class);

beforeEach(function () {
    $this->connectToIntegrationServer();
});
/**
 * Scout engine search paths against a real server: vector search through
 * multi-search, escaped filters, and semantic()/hybrid() with native
 * (Typesense-generated) embeddings.
 */

trait EngineSearchIntegrationTestHelpers
{
    protected const VECTORS = 'integration_engine_vectors';

    protected const SEMANTIC = 'integration_engine_semantic';

    protected function model(string $collection, array $schema, array $queryBy = ['title']): object
    {
        return new class($collection, $schema, $queryBy) {
            public function __construct(private string $collection, private array $schema, private array $queryBy)
            {
            }

            public function searchableAs(): string
            {
                return $this->collection;
            }

            public function getCollectionSchema(): array
            {
                return ['name' => $this->collection] + $this->schema;
            }

            public function typesenseQueryBy(): array
            {
                return $this->queryBy;
            }
        };
    }

    protected function vector(float $lead): array
    {
        // 1536 dimensions — the query string alone would be ~15k characters.
        return array_merge([$lead, 1 - $lead], array_fill(0, 1534, 0.001));
    }

    protected function seedVectors(): object
    {
        $this->dropCollection(self::VECTORS);

        $model = $this->model(self::VECTORS, ['fields' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'tag', 'type' => 'string'],
            ['name' => 'embedding', 'type' => 'float[]', 'num_dim' => 1536],
        ]]);

        $this->client->getCollections()->create($model->getCollectionSchema());

        $documents = $this->client->getCollections()[self::VECTORS]->getDocuments();
        $documents->create(['id' => '1', 'title' => 'North', 'tag' => 'a && b, [c]', 'embedding' => $this->vector(0.99)]);
        $documents->create(['id' => '2', 'title' => 'South', 'tag' => 'a', 'embedding' => $this->vector(0.01)]);

        return $model;
    }
}

uses(EngineSearchIntegrationTestHelpers::class);

afterEach(function () {
    if (isset($this->client)) {
        $this->dropCollection(self::VECTORS);
        $this->dropCollection(self::SEMANTIC);
    }
});

test('large vector query is sent through multi search', function () {
    $model = $this->seedVectors();

    $engine = new TypesenseEngine($this->typesense);
    $builder = (new Builder($model, '*'))->nearestNeighbors('embedding', $this->vector(0.98), k: 2);

    $results = $engine->search($builder);

    expect($results['found'])->toBe(2);
    expect($engine->mapIds($results)->all())->toBe(['1', '2']);
});

test('escaped filter values match literally', function () {
    $model = $this->seedVectors();

    $engine = new TypesenseEngine($this->typesense, ['escape_filter_values' => true]);

    $builder = new Builder($model, '*');
    $builder->where('tag', 'a && b, [c]');

    $results = $engine->search($builder);

    expect($results['found'])->toBe(1);
    expect($results['hits'][0]['document']['id'])->toBe('1');
});

test('native semantic and hybrid search', function () {
    $this->dropCollection(self::SEMANTIC);

    $model = $this->model(self::SEMANTIC, ['fields' => [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'embedding', 'type' => 'float[]', 'embed' => [
            'from' => ['title'],
            'model_config' => ['model_name' => 'ts/all-MiniLM-L12-v2'],
        ]],
    ]]);

    try {
        // The first run downloads the built-in embedding model.
        $this->client->getCollections()->create($model->getCollectionSchema());
    } catch (\Throwable $e) {
        skip('Built-in embedding model unavailable: ' . $e->getMessage());
    }

    $documents = $this->client->getCollections()[self::SEMANTIC]->getDocuments();
    $documents->create(['id' => '1', 'title' => 'A recipe for chocolate cake']);
    $documents->create(['id' => '2', 'title' => 'Changing the oil in your car']);

    $engine = new TypesenseEngine($this->typesense, ['model-settings' => [
        get_class($model) => ['embedding' => ['driver' => 'typesense', 'attribute' => 'embedding']],
    ]]);

    $semantic = $engine->search((new Builder($model, 'baking a dessert'))->semantic());
    expect($semantic['hits'][0]['document']['id'])->toBe('1');
    expect($semantic['hits'][0]['document'])->not->toHaveKey('embedding');

    $hybrid = $engine->search((new Builder($model, 'engine maintenance'))->hybrid());
    expect($hybrid['hits'][0]['document']['id'])->toBe('2');
});
