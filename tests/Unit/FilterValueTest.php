<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * Tests for filter-value normalisation and operator/range filters.
 */
class FilterValueTest extends TestCase
{
    private function engine(): TypesenseEngine
    {
        return (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    }

    private function callFilters(Builder $builder): string
    {
        $method = (new ReflectionClass(TypesenseEngine::class))->getMethod('filters');
        $method->setAccessible(true);

        return $method->invoke($this->engine(), $builder);
    }

    public function test_boolean_values_become_typesense_literals(): void
    {
        $this->assertSame('true', $this->engine()->parseFilterValue(true));
        $this->assertSame('false', $this->engine()->parseFilterValue(false));
    }

    public function test_scalar_values_pass_through(): void
    {
        $this->assertSame(5, $this->engine()->parseFilterValue(5));
        $this->assertSame('active', $this->engine()->parseFilterValue('active'));
    }

    public function test_arrays_are_normalised_recursively(): void
    {
        $this->assertSame(['true', 'false', 1], $this->engine()->parseFilterValue([true, false, 1]));
    }

    public function test_boolean_where_renders_as_literal(): void
    {
        $builder = new Builder(new \stdClass, '');
        $builder->wheres = ['active' => true];

        $this->assertSame('active:=true', $this->callFilters($builder));
    }

    public function test_operator_array_renders_comparison_filter(): void
    {
        $builder = new Builder(new \stdClass, '');
        $builder->wheres = ['price' => ['>', 100]];

        $this->assertSame('price:>100', $this->callFilters($builder));
    }

    public function test_range_array_renders_range_filter(): void
    {
        $builder = new Builder(new \stdClass, '');
        $builder->wheres = ['price' => ['[10..100]']];

        $this->assertSame('price:[10..100]', $this->callFilters($builder));
    }
}
