<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * Tests for filter-value normalisation and operator/range filters.
 */

trait FilterValueTestHelpers
{
    protected function engine(): TypesenseEngine
    {
        return (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    }

    protected function callFilters(Builder $builder): string
    {
        $method = (new ReflectionClass(TypesenseEngine::class))->getMethod('filters');

        return $method->invoke($this->engine(), $builder);
    }
}

uses(FilterValueTestHelpers::class);

test('boolean values become typesense literals', function () {
    expect($this->engine()->parseFilterValue(true))->toBe('true');
    expect($this->engine()->parseFilterValue(false))->toBe('false');
});

test('scalar values pass through', function () {
    expect($this->engine()->parseFilterValue(5))->toBe(5);
    expect($this->engine()->parseFilterValue('active'))->toBe('active');
});

test('arrays are normalised recursively', function () {
    expect($this->engine()->parseFilterValue([true, false, 1]))->toBe(['true', 'false', 1]);
});

test('boolean where renders as literal', function () {
    $builder = new Builder(new \stdClass, '');
    $builder->wheres = ['active' => true];

    expect($this->callFilters($builder))->toBe('active:=true');
});

test('operator array renders comparison filter', function () {
    $builder = new Builder(new \stdClass, '');
    $builder->wheres = ['price' => ['>', 100]];

    expect($this->callFilters($builder))->toBe('price:>100');
});

test('range array renders range filter', function () {
    $builder = new Builder(new \stdClass, '');
    $builder->wheres = ['price' => ['[10..100]']];

    expect($this->callFilters($builder))->toBe('price:[10..100]');
});
