<?php

namespace Siberfx\Typesense\Tests\Unit;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Laravel\Scout\Builder;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;

class EngineMapStubRecord
{
    public function __construct(private readonly int|string $key)
    {
    }

    public function getScoutKey(): int|string
    {
        return $this->key;
    }
}

trait EngineMapTestHelpers
{
    protected function engine(): TypesenseEngine
    {
        return (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    }
}

uses(EngineMapTestHelpers::class);

test('map returns hits in result order and drops unknown ids', function () {
    // Typesense ranks 2, then 1, then 9. Id 9 no longer exists in the database.
    $results = [
        'found' => 3,
        'hits' => [
            ['document' => ['id' => '2']],
            ['document' => ['id' => '1']],
            ['document' => ['id' => '9']],
        ],
    ];

    $model = new class {
        public function getScoutModelsByIds(Builder $builder, array $ids): EloquentCollection
        {
            // Database order is unrelated to search rank.
            return new EloquentCollection([new EngineMapStubRecord(1), new EngineMapStubRecord(2)]);
        }

        public function newCollection(array $models = []): EloquentCollection
        {
            return new EloquentCollection($models);
        }
    };

    $mapped = $this->engine()->map(new Builder(new \stdClass, ''), $results, $model);

    expect(array_map(static fn ($record) => (string) $record->getScoutKey(), $mapped->all()))->toBe(['2', '1']);
});

test('map returns an empty collection when nothing matched', function () {
    $model = new class {
        public function newCollection(array $models = []): EloquentCollection
        {
            return new EloquentCollection($models);
        }
    };

    $mapped = $this->engine()->map(new Builder(new \stdClass, ''), ['found' => 0, 'hits' => []], $model);

    expect($mapped)->toHaveCount(0);
});
