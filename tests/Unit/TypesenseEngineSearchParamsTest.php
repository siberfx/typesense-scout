<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * Regression tests for search parameter filtering, grouped-hit id extraction
 * and the per-query engine state reset. All pure logic — no server required.
 */
class TypesenseEngineSearchParamsTest extends TestCase
{
    private function engine(): TypesenseEngine
    {
        // Avoid the constructor so we don't need a live Typesense client.
        return (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    }

    private function builder(string $query): Builder
    {
        $model = new class {
            public function typesenseQueryBy(): array
            {
                return ['name'];
            }
        };

        return new Builder($model, $query);
    }

    private function invokePrivate(TypesenseEngine $engine, string $method, array $args = []): mixed
    {
        $ref = (new ReflectionClass(TypesenseEngine::class))->getMethod($method);
        $ref->setAccessible(true);

        return $ref->invoke($engine, ...$args);
    }

    private function buildFilteredParams(TypesenseEngine $engine, Builder $builder, int $page = 1, ?int $perPage = 10): array
    {
        $params = $this->invokePrivate($engine, 'buildSearchParams', [$builder, $page, $perPage]);

        return $this->invokePrivate($engine, 'filterSearchParams', [$params]);
    }

    public function test_boolean_false_options_are_not_stripped(): void
    {
        $engine = $this->engine();
        $engine->enableOverrides(false);
        $engine->setPrioritizeExactMatch(false);

        $params = $this->buildFilteredParams($engine, $this->builder('shoes'));

        $this->assertArrayHasKey('enable_overrides', $params);
        $this->assertFalse($params['enable_overrides']);
        $this->assertArrayHasKey('prioritize_exact_match', $params);
        $this->assertFalse($params['prioritize_exact_match']);
    }

    public function test_empty_query_is_preserved_for_filter_only_searches(): void
    {
        $params = $this->buildFilteredParams($this->engine(), $this->builder(''));

        $this->assertArrayHasKey('q', $params);
        $this->assertSame('', $params['q']);
    }

    public function test_unset_string_and_null_params_are_dropped(): void
    {
        // No wheres -> empty filter_by; null perPage -> per_page unset.
        $params = $this->buildFilteredParams($this->engine(), $this->builder('shoes'), 1, null);

        $this->assertArrayNotHasKey('filter_by', $params);
        $this->assertArrayNotHasKey('per_page', $params);
    }

    public function test_map_ids_reads_plain_hits(): void
    {
        $ids = $this->engine()->mapIds([
            'found' => 2,
            'hits' => [
                ['document' => ['id' => '1']],
                ['document' => ['id' => '2']],
            ],
        ]);

        $this->assertSame(['1', '2'], $ids->all());
    }

    public function test_map_ids_reads_grouped_hits(): void
    {
        $ids = $this->engine()->mapIds([
            'found' => 2,
            'grouped_hits' => [
                ['group_key' => ['a'], 'hits' => [['document' => ['id' => '3']]]],
                ['group_key' => ['b'], 'hits' => [['document' => ['id' => '4']]]],
            ],
        ]);

        $this->assertSame(['3', '4'], $ids->all());
    }

    public function test_map_ids_tolerates_missing_hits(): void
    {
        $this->assertSame([], $this->engine()->mapIds(['found' => 0])->all());
    }

    public function test_reset_restores_per_query_defaults(): void
    {
        $engine = $this->engine();
        $engine->groupBy(['category'])
            ->facetBy(['brand'])
            ->vectorQuery('embedding:([0.1], k:5)')
            ->searchMulti([['collection' => 'a', 'q' => 'x']])
            ->enableOverrides(false);

        $this->invokePrivate($engine, 'resetSearchParameters');

        $params = $this->buildFilteredParams($engine, $this->builder('shoes'));

        $this->assertArrayNotHasKey('group_by', $params);
        $this->assertArrayNotHasKey('facet_by', $params);
        $this->assertArrayNotHasKey('vector_query', $params);
        $this->assertTrue($params['enable_overrides']);

        // The multi-search option list is cleared too, so the next search is
        // a normal single-collection search again.
        $ref = (new ReflectionClass(TypesenseEngine::class))->getProperty('optionsMulti');
        $ref->setAccessible(true);
        $this->assertSame([], $ref->getValue($engine));
    }
}
