<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * Regression tests for search parameter filtering, grouped-hit id extraction
 * and the per-query engine state reset. All pure logic — no server required.
 */

trait TypesenseEngineSearchParamsTestHelpers
{
    protected function engine(): TypesenseEngine
    {
        // Avoid the constructor so we don't need a live Typesense client.
        return (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    }

    protected function builder(string $query): Builder
    {
        $model = new class {
            public function typesenseQueryBy(): array
            {
                return ['name'];
            }
        };

        return new Builder($model, $query);
    }

    protected function invokePrivate(TypesenseEngine $engine, string $method, array $args = []): mixed
    {
        $ref = (new ReflectionClass(TypesenseEngine::class))->getMethod($method);

        return $ref->invoke($engine, ...$args);
    }

    protected function buildFilteredParams(TypesenseEngine $engine, Builder $builder, int $page = 1, ?int $perPage = 10): array
    {
        $params = $this->invokePrivate($engine, 'buildSearchParams', [$builder, $page, $perPage]);

        return $this->invokePrivate($engine, 'filterSearchParams', [$params]);
    }
}

uses(TypesenseEngineSearchParamsTestHelpers::class);

test('boolean false options are not stripped', function () {
    $builder = $this->builder('shoes')->enableOverrides(false)->setPrioritizeExactMatch(false);

    $params = $this->buildFilteredParams($this->engine(), $builder);

    expect($params)->toHaveKey('enable_overrides');
    expect($params['enable_overrides'])->toBeFalse();
    expect($params)->toHaveKey('prioritize_exact_match');
    expect($params['prioritize_exact_match'])->toBeFalse();
});

test('empty query is preserved for filter only searches', function () {
    $params = $this->buildFilteredParams($this->engine(), $this->builder(''));

    expect($params)->toHaveKey('q');
    expect($params['q'])->toBe('');
});

test('unset string and null params are dropped', function () {
    // No wheres -> empty filter_by; null perPage -> per_page unset.
    $params = $this->buildFilteredParams($this->engine(), $this->builder('shoes'), 1, null);

    expect($params)->not->toHaveKey('filter_by');
    expect($params)->not->toHaveKey('per_page');
});

test('map ids reads plain hits', function () {
    $ids = $this->engine()->mapIds([
        'found' => 2,
        'hits' => [
            ['document' => ['id' => '1']],
            ['document' => ['id' => '2']],
        ],
    ]);

    expect($ids->all())->toBe(['1', '2']);
});

test('map ids reads grouped hits', function () {
    $ids = $this->engine()->mapIds([
        'found' => 2,
        'grouped_hits' => [
            ['group_key' => ['a'], 'hits' => [['document' => ['id' => '3']]]],
            ['group_key' => ['b'], 'hits' => [['document' => ['id' => '4']]]],
        ],
    ]);

    expect($ids->all())->toBe(['3', '4']);
});

test('map ids tolerates missing hits', function () {
    expect($this->engine()->mapIds(['found' => 0])->all())->toBe([]);
});

test('options belong to their own builder', function () {
    $tenant = $this->builder('shoes')
        ->groupBy(['category'])
        ->facetBy(['brand'])
        ->vectorQuery('embedding:([0.1], k:5)')
        ->enableOverrides(false);

    // A later, unrelated query must not inherit any of them.
    $other = $this->builder('boots');
    $params = $this->buildFilteredParams($this->engine(), $other);

    expect($params)->not->toHaveKey('group_by');
    expect($params)->not->toHaveKey('facet_by');
    expect($params)->not->toHaveKey('vector_query');
    expect($params['enable_overrides'])->toBeTrue();

    // And the first query keeps its own options.
    $tenantParams = $this->buildFilteredParams($this->engine(), $tenant);
    expect($tenantParams['group_by'])->toBe('category');
    expect($tenantParams['enable_overrides'])->toBeFalse();
});
