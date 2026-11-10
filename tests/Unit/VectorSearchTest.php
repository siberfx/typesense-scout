<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * Tests for vector / hybrid semantic search parameter building.
 */

trait VectorSearchTestHelpers
{
    protected function engine(): TypesenseEngine
    {
        return (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    }

    protected function builder(string $query): Builder
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

    protected function buildParams(TypesenseEngine $engine, Builder $builder): array
    {
        $method = (new ReflectionClass(TypesenseEngine::class))->getMethod('buildSearchParams');

        return $method->invoke($engine, $builder, 1, 10);
    }
}

uses(VectorSearchTestHelpers::class);

test('no vector query when unset', function () {
    $params = $this->buildParams($this->engine(), $this->builder('shoes'));

    expect($params)->not->toHaveKey('vector_query');
});

test('raw vector query is passed through', function () {
    $builder = $this->builder('*')->vectorQuery('embedding:([0.1, 0.2], k:5)');

    $params = $this->buildParams($this->engine(), $builder);

    expect($params['vector_query'])->toBe('embedding:([0.1, 0.2], k:5)');
});

test('nearest neighbors builds basic query', function () {
    $builder = $this->builder('*')->nearestNeighbors('embedding', [0.1, 0.2, 0.3], 10);

    $params = $this->buildParams($this->engine(), $builder);

    expect($params['vector_query'])->toBe('embedding:([0.1, 0.2, 0.3], k:10)');
});

test('nearest neighbors includes distance threshold and alpha', function () {
    $builder = $this->builder('hybrid query')->nearestNeighbors('embedding', [0.5, 0.6], 8, 0.3, 0.4);

    $params = $this->buildParams($this->engine(), $builder);

    expect($params['vector_query'])->toBe('embedding:([0.5, 0.6], k:8, distance_threshold:0.3, alpha:0.4)');
    // Hybrid: the text query is preserved alongside the vector query.
    expect($params['q'])->toBe('hybrid query');
});

test('nearest neighbors returns the builder for chaining', function () {
    $builder = $this->builder('*');

    expect($builder->nearestNeighbors('embedding', [0.1], 3))->toBe($builder);
});
