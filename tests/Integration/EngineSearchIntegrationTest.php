<?php

namespace Siberfx\Typesense\Tests\Integration;

use Laravel\Scout\Builder;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * Scout engine search paths against a real server: vector search through
 * multi-search, escaped filters, and semantic()/hybrid() with native
 * (Typesense-generated) embeddings.
 */
class EngineSearchIntegrationTest extends IntegrationTestCase
{
    private const VECTORS = 'integration_engine_vectors';

    private const SEMANTIC = 'integration_engine_semantic';

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $this->dropCollection(self::VECTORS);
            $this->dropCollection(self::SEMANTIC);
        }

        parent::tearDown();
    }

    private function model(string $collection, array $schema, array $queryBy = ['title']): object
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

    private function vector(float $lead): array
    {
        // 1536 dimensions — the query string alone would be ~15k characters.
        return array_merge([$lead, 1 - $lead], array_fill(0, 1534, 0.001));
    }

    private function seedVectors(): object
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

    public function test_large_vector_query_is_sent_through_multi_search(): void
    {
        $model = $this->seedVectors();

        $engine = new TypesenseEngine($this->typesense);
        $engine->nearestNeighbors('embedding', $this->vector(0.98), k: 2);

        $results = $engine->search(new Builder($model, '*'));

        $this->assertSame(2, $results['found']);
        $this->assertSame(['1', '2'], $engine->mapIds($results)->all());
    }

    public function test_escaped_filter_values_match_literally(): void
    {
        $model = $this->seedVectors();

        $engine = new TypesenseEngine($this->typesense, ['escape_filter_values' => true]);

        $builder = new Builder($model, '*');
        $builder->where('tag', 'a && b, [c]');

        $results = $engine->search($builder);

        $this->assertSame(1, $results['found']);
        $this->assertSame('1', $results['hits'][0]['document']['id']);
    }

    public function test_native_semantic_and_hybrid_search(): void
    {
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
            $this->markTestSkipped('Built-in embedding model unavailable: ' . $e->getMessage());
        }

        $documents = $this->client->getCollections()[self::SEMANTIC]->getDocuments();
        $documents->create(['id' => '1', 'title' => 'A recipe for chocolate cake']);
        $documents->create(['id' => '2', 'title' => 'Changing the oil in your car']);

        $engine = new TypesenseEngine($this->typesense, ['model-settings' => [
            get_class($model) => ['embedding' => ['driver' => 'typesense', 'attribute' => 'embedding']],
        ]]);

        $semantic = $engine->search((new Builder($model, 'baking a dessert'))->semantic());
        $this->assertSame('1', $semantic['hits'][0]['document']['id']);
        $this->assertArrayNotHasKey('embedding', $semantic['hits'][0]['document']);

        $hybrid = $engine->search((new Builder($model, 'engine maintenance'))->hybrid());
        $this->assertSame('2', $hybrid['hits'][0]['document']['id']);
    }
}
