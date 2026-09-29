<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;
use Siberfx\Typesense\Typesense;

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

    public function test_filters_from_scout_builder_where_calls(): void
    {
        // Scout 11 stores where() clauses as ['field', 'operator', 'value'].
        $builder = $this->builder()
            ->where('status', 'active')
            ->where('price', '>', 100)
            ->where('stock', '!=', 0)
            ->where('rating', ['[3..5]'])
            ->where('featured', true)
            ->whereIn('type', ['a', 'b']);

        $this->assertSame(
            'status:=active && price:>100 && stock:!=0 && rating:[3..5] && featured:=true && type:=[a, b]',
            $this->callFilters($builder)
        );
    }

    public function test_escape_filter_value_matches_typesense_filter_by_escape(): void
    {
        $this->assertSame('`O\'Conner && a || [b]`', TypesenseEngine::escapeFilterValue("O'Conner && a || [b]"));
        $this->assertSame('`17\\` series`', TypesenseEngine::escapeFilterValue('17` series'));
        $this->assertSame('42', TypesenseEngine::escapeFilterValue(42));
        $this->assertSame('1.5', TypesenseEngine::escapeFilterValue(1.5));
        $this->assertSame('false', TypesenseEngine::escapeFilterValue(false));
    }

    public function test_values_are_not_escaped_by_default(): void
    {
        $builder = $this->builder();
        $builder->wheres = ['name' => 'a && b'];

        $this->assertSame('name:=a && b', $this->callFilters($builder));
    }

    public function test_escape_filter_values_option_escapes_where_and_where_in_values(): void
    {
        $engine = new TypesenseEngine($this->createStub(Typesense::class), ['escape_filter_values' => true]);

        $builder = $this->builder();
        $builder->wheres = ['name' => 'a && b', 'price' => ['>', 100], 'active' => true, 'team_id' => '7'];
        $builder->whereIns = ['tags' => ['x, y', 'z']];
        $builder->whereNotIns = ['id' => [1, '2']];

        $method = (new ReflectionClass(TypesenseEngine::class))->getMethod('filters');

        $this->assertSame(
            'name:=`a && b` && price:>100 && active:=true && team_id:=7 && tags:=[`x, y`, `z`] && id:!=[1, 2]',
            $method->invoke($engine, $builder)
        );
    }
}
