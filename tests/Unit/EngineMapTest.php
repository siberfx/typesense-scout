<?php

namespace Siberfx\Typesense\Tests\Unit;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Laravel\Scout\Builder;
use PHPUnit\Framework\TestCase;
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

class EngineMapTest extends TestCase
{
    private function engine(): TypesenseEngine
    {
        return (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    }

    public function test_map_returns_hits_in_result_order_and_drops_unknown_ids(): void
    {
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

        $this->assertSame(
            ['2', '1'],
            array_map(static fn ($record) => (string) $record->getScoutKey(), $mapped->all())
        );
    }

    public function test_map_returns_an_empty_collection_when_nothing_matched(): void
    {
        $model = new class {
            public function newCollection(array $models = []): EloquentCollection
            {
                return new EloquentCollection($models);
            }
        };

        $mapped = $this->engine()->map(new Builder(new \stdClass, ''), ['found' => 0, 'hits' => []], $model);

        $this->assertCount(0, $mapped);
    }
}
