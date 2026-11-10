<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;
use Siberfx\Typesense\Typesense;

/**
 * Pure-logic tests for the Typesense filter builder. These exercise the
 * `filter_by` string generation without requiring a running Typesense server.
 */

trait TypesenseEngineFilterTestHelpers
{
    protected function engine(): TypesenseEngine
    {
        // Avoid the constructor so we don't need a live Typesense client.
        return (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    }

    protected function builder(): Builder
    {
        // The Builder constructor only assigns properties; the model is never
        // touched by filters(), so a lightweight stdClass stand-in is fine.
        return new Builder(new \stdClass, '');
    }

    protected function callFilters(Builder $builder): string
    {
        $method = (new ReflectionClass(TypesenseEngine::class))->getMethod('filters');

        return $method->invoke($this->engine(), $builder);
    }
}

uses(TypesenseEngineFilterTestHelpers::class);

test('where produces equality filter', function () {
    expect($this->engine()->parseWhereFilter(5, 'id'))->toBe('id:=5');
});

test('where in produces array filter', function () {
    expect($this->engine()->parseWhereInFilter([1, 2, 3], 'id'))->toBe('id:=[1, 2, 3]');
});

test('where not in produces negated array filter', function () {
    expect($this->engine()->parseWhereNotInFilter([4, 5], 'id'))->toBe('id:!=[4, 5]');
});

test('filters combines where where in and where not in', function () {
    $builder = $this->builder();
    $builder->wheres = ['status' => 'active'];
    $builder->whereIns = ['type' => ['a', 'b']];
    $builder->whereNotIns = ['team' => [9, 10]];

    expect($this->callFilters($builder))->toBe('status:=active && type:=[a, b] && team:!=[9, 10]');
});

test('filters only where not in', function () {
    $builder = $this->builder();
    $builder->whereNotIns = ['team' => [9, 10]];

    expect($this->callFilters($builder))->toBe('team:!=[9, 10]');
});

test('filters empty when no clauses', function () {
    expect($this->callFilters($this->builder()))->toBe('');
});

test('filters from scout builder where calls', function () {
    // Scout 11 stores where() clauses as ['field', 'operator', 'value'].
    $builder = $this->builder()
        ->where('status', 'active')
        ->where('price', '>', 100)
        ->where('stock', '!=', 0)
        ->where('rating', ['[3..5]'])
        ->where('featured', true)
        ->whereIn('type', ['a', 'b']);

    expect($this->callFilters($builder))->toBe('status:=active && price:>100 && stock:!=0 && rating:[3..5] && featured:=true && type:=[a, b]');
});

test('escape filter value matches typesense filter by escape', function () {
    expect(TypesenseEngine::escapeFilterValue("O'Conner && a || [b]"))->toBe('`O\'Conner && a || [b]`');
    expect(TypesenseEngine::escapeFilterValue('17` series'))->toBe('`17\\` series`');
    expect(TypesenseEngine::escapeFilterValue(42))->toBe('42');
    expect(TypesenseEngine::escapeFilterValue(1.5))->toBe('1.5');
    expect(TypesenseEngine::escapeFilterValue(false))->toBe('false');
});

test('values are not escaped by default', function () {
    $builder = $this->builder();
    $builder->wheres = ['name' => 'a && b'];

    expect($this->callFilters($builder))->toBe('name:=a && b');
});

test('where in quotes string values that contain list syntax', function () {
    $builder = $this->builder();
    $builder->whereIns = ['tag' => ['a,b', 'plain word', 'plain', 42]];

    // Without quoting, "a,b" and "plain word" split into several list items.
    expect($this->callFilters($builder))->toBe('tag:=[`a,b`, `plain word`, plain, 42]');
});

test('where not in quotes string values that contain list syntax', function () {
    $builder = $this->builder();
    $builder->whereNotIns = ['tag' => ['Red, Blue', 'x']];

    expect($this->callFilters($builder))->toBe('tag:!=[`Red, Blue`, x]');
});

test('escape filter values option escapes where and where in values', function () {
    $engine = new TypesenseEngine($this->createStub(Typesense::class), ['escape_filter_values' => true]);

    $builder = $this->builder();
    $builder->wheres = ['name' => 'a && b', 'price' => ['>', 100], 'active' => true, 'team_id' => '7'];
    $builder->whereIns = ['tags' => ['x, y', 'z']];
    $builder->whereNotIns = ['id' => [1, '2']];

    $method = (new ReflectionClass(TypesenseEngine::class))->getMethod('filters');

    expect($method->invoke($engine, $builder))->toBe('name:=`a && b` && price:>100 && active:=true && team_id:=7 && tags:=[`x, y`, `z`] && id:!=[1, 2]');
});
