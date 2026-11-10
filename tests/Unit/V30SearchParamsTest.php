<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * Typesense server v30 search parameters: synonym sets, curation tags,
 * diversity (MMR), group_max_candidates and the Scout options() passthrough.
 */

trait V30SearchParamsTestHelpers
{
    protected function engine(): TypesenseEngine
    {
        // Avoid the constructor so we don't need a live Typesense client.
        return (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    }

    protected function builder(string $query = 'shoes'): Builder
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
        return (new ReflectionClass(TypesenseEngine::class))->getMethod($method)->invoke($engine, ...$args);
    }

    protected function params(TypesenseEngine $engine, ?Builder $builder = null): array
    {
        $params = $this->invokePrivate($engine, 'buildSearchParams', [$builder ?? $this->builder(), 1, 10]);

        return $this->invokePrivate($engine, 'filterSearchParams', [$params]);
    }
}

uses(V30SearchParamsTestHelpers::class);

test('synonym sets and curation tags are sent as comma lists', function () {
    $builder = $this->builder()->synonymSets(['clothing', 'tech'])->curationTags('summer');

    $params = $this->params($this->engine(), $builder);

    expect($params['synonym_sets'])->toBe('clothing,tech');
    expect($params['curation_tags'])->toBe('summer');
});

test('diversity lambda and group max candidates are sent', function () {
    $builder = $this->builder()->diversityLambda(0.75)->groupMaxCandidates(500);

    $params = $this->params($this->engine(), $builder);

    expect($params['diversity_lambda'])->toBe(0.75);
    expect($params['group_max_candidates'])->toBe(500);
});

test('unset v30 params are not sent', function () {
    $params = $this->params($this->engine());

    foreach (['synonym_sets', 'curation_tags', 'diversity_lambda', 'group_max_candidates'] as $key) {
        expect($params)->not->toHaveKey($key);
    }
});

test('builder options are passed through and override defaults', function () {
    $builder = $this->builder()->options([
        'facet_sample_slope' => 0.5,
        'facet_return_parent' => 'variants.color',
        'prioritize_exact_match' => false,
    ]);

    $params = $this->params($this->engine(), $builder);

    expect($params['facet_sample_slope'])->toBe(0.5);
    expect($params['facet_return_parent'])->toBe('variants.color');
    expect($params['prioritize_exact_match'])->toBeFalse();
});

test('scout vector option is not sent as a search param', function () {
    $builder = $this->builder()->options(['vector' => [0.1, 0.2]]);

    expect($this->params($this->engine(), $builder))->not->toHaveKey('vector');
});

test('v30 params do not leak into other queries', function () {
    $this->params($this->engine(), $this->builder()->synonymSets('clothing')->curationTags('summer')
        ->diversityLambda(0.3)->groupMaxCandidates(100));

    expect(array_intersect_key(
        $this->params($this->engine(), $this->builder()),
        array_flip(['synonym_sets', 'curation_tags', 'diversity_lambda', 'group_max_candidates'])
    ))->toBe([]);
});
