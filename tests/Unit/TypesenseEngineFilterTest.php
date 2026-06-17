<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * Pure-logic tests for the Typesense filter builder. These exercise the
 * `filter_by` string generation without requiring a running Typesense server.
 */
class TypesenseEngineFilterTest extends TestCase
{
    private function engine(): TypesenseEngine
    {
        // Avoid the constructor so we don't need a live Typesense client.
        return (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    }

    private function builder(): Builder
    {
        // The Builder constructor only assigns properties; the model is never
        // touched by filters(), so a lightweight stdClass stand-in is fine.
        return new Builder(new \stdClass, '');
    }

    private function callFilters(Builder $builder): string
    {
        $method = (new ReflectionClass(TypesenseEngine::class))->getMethod('filters');
        $method->setAccessible(true);

        return $method->invoke($this->engine(), $builder);
    }

    public function test_where_produces_equality_filter(): void
    {
        $this->assertSame('id:=5', $this->engine()->parseWhereFilter(5, 'id'));
    }

    public function test_where_in_produces_array_filter(): void
    {
        $this->assertSame('id:=[1, 2, 3]', $this->engine()->parseWhereInFilter([1, 2, 3], 'id'));
    }

    public function test_where_not_in_produces_negated_array_filter(): void
    {
        $this->assertSame('id:!=[4, 5]', $this->engine()->parseWhereNotInFilter([4, 5], 'id'));
    }

    public function test_filters_combines_where_where_in_and_where_not_in(): void
    {
        $builder = $this->builder();
        $builder->wheres = ['status' => 'active'];
        $builder->whereIns = ['type' => ['a', 'b']];
        $builder->whereNotIns = ['team' => [9, 10]];

        $this->assertSame(
            'status:=active && type:=[a, b] && team:!=[9, 10]',
            $this->callFilters($builder)
        );
    }

    public function test_filters_only_where_not_in(): void
    {
        $builder = $this->builder();
        $builder->whereNotIns = ['team' => [9, 10]];

        $this->assertSame('team:!=[9, 10]', $this->callFilters($builder));
    }

    public function test_filters_empty_when_no_clauses(): void
    {
        $this->assertSame('', $this->callFilters($this->builder()));
    }
}
