<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * Records the search parameters instead of sending them.
 */
class BuilderCountRecordingEngine extends TypesenseEngine
{
    public array $sent = [];

    public function __construct()
    {
    }

    protected function performSearch(Builder $builder, array $options = []): mixed
    {
        $this->sent[] = $options;

        return ['found' => 42, 'hits' => []];
    }
}

trait BuilderCountTestHelpers
{
    protected function builder(BuilderCountRecordingEngine $engine): Builder
    {
        $model = new class($engine) {
            public function __construct(private BuilderCountRecordingEngine $engine)
            {
            }

            public function searchableUsing(): BuilderCountRecordingEngine
            {
                return $this->engine;
            }

            public function typesenseQueryBy(): array
            {
                return ['title'];
            }
        };

        return new Builder($model, 'shoes');
    }
}

uses(BuilderCountTestHelpers::class);

test('count returns the total without fetching hits', function () {
    $engine = new BuilderCountRecordingEngine();

    $count = $this->builder($engine)->count();

    expect($count)->toBe(42);
    expect($engine->sent)->toHaveCount(1);
    expect($engine->sent[0])->toHaveKey('per_page');
    expect($engine->sent[0]['per_page'])->toBe(0);
});

test('count keeps the builder limit', function () {
    $engine = new BuilderCountRecordingEngine();
    $builder = $this->builder($engine)->take(5);

    $builder->count();

    expect($builder->limit)->toBe(5);
});

test('count restores the limit when the search fails', function () {
    $engine = new class extends BuilderCountRecordingEngine {
        protected function performSearch(Builder $builder, array $options = []): mixed
        {
            throw new \RuntimeException('Search failed.');
        }
    };
    $builder = $this->builder($engine)->take(5);

    expect(fn () => $builder->count())->toThrow(\RuntimeException::class);
    expect($builder->limit)->toBe(5);
});
