<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * Tests for vector / hybrid semantic search parameter building.
 */
class VectorSearchTest extends TestCase
{
    private function engine(): TypesenseEngine
    {
        return (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    }

    private function builder(string $query): Builder
    {
        // buildSearchParams() calls $builder->model->typesenseQueryBy().
        $model = new class {
            public function typesenseQueryBy(): array
            {
                return ['name'];
            }
        };

        return new Builder($model, $query);
    }

    private function buildParams(TypesenseEngine $engine, Builder $builder): array
    {
        $method = (new ReflectionClass(TypesenseEngine::class))->getMethod('buildSearchParams');
        $method->setAccessible(true);

        return $method->invoke($engine, $builder, 1, 10);
    }

    public function test_no_vector_query_when_unset(): void
    {
        $params = $this->buildParams($this->engine(), $this->builder('shoes'));

        $this->assertArrayNotHasKey('vector_query', $params);
    }

    public function test_raw_vector_query_is_passed_through(): void
    {
        $engine = $this->engine();
        $engine->vectorQuery('embedding:([0.1, 0.2], k:5)');

        $params = $this->buildParams($engine, $this->builder('*'));

        $this->assertSame('embedding:([0.1, 0.2], k:5)', $params['vector_query']);
    }

    public function test_nearest_neighbors_builds_basic_query(): void
    {
        $engine = $this->engine();
        $engine->nearestNeighbors('embedding', [0.1, 0.2, 0.3], 10);

        $params = $this->buildParams($engine, $this->builder('*'));

        $this->assertSame('embedding:([0.1, 0.2, 0.3], k:10)', $params['vector_query']);
    }

    public function test_nearest_neighbors_includes_distance_threshold_and_alpha(): void
    {
        $engine = $this->engine();
        $engine->nearestNeighbors('embedding', [0.5, 0.6], 8, 0.3, 0.4);

        $params = $this->buildParams($engine, $this->builder('hybrid query'));

        $this->assertSame(
            'embedding:([0.5, 0.6], k:8, distance_threshold:0.3, alpha:0.4)',
            $params['vector_query']
        );
        // Hybrid: the text query is preserved alongside the vector query.
        $this->assertSame('hybrid query', $params['q']);
    }

    public function test_nearest_neighbors_returns_engine_for_chaining(): void
    {
        $engine = $this->engine();

        $this->assertSame($engine, $engine->nearestNeighbors('embedding', [0.1], 3));
    }
}
