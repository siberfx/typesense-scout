<?php

namespace Siberfx\Typesense\Tests\Unit;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Siberfx\Typesense\Engines\TypesenseEngine;
use Siberfx\Typesense\Typesense;
use Typesense\Collection;

class EngineDeleteStubModel
{
    public function __construct(private readonly int|string $key)
    {
    }

    public function getScoutKey(): int|string
    {
        return $this->key;
    }

    public function searchableAs(): string
    {
        return 'todos';
    }
}

trait EngineDeleteTestHelpers
{
    /** @var array<int, array> Queries passed to deleteDocuments(). */
    protected array $deleteQueries = [];

    protected int $collectionLookups = 0;

    protected function engine(): TypesenseEngine
    {
        $collection = $this->createStub(Collection::class);

        $typesense = $this->createMock(Typesense::class);
        $typesense->method('withCollection')->willReturnCallback(function ($model, \Closure $operation) use ($collection) {
            $this->collectionLookups++;

            return $operation($collection);
        });
        $typesense->method('deleteDocuments')->willReturnCallback(function ($collection, array $query) {
            $this->deleteQueries[] = $query;

            return ['num_deleted' => 0];
        });
        $typesense->expects($this->never())->method('deleteDocument');

        return new TypesenseEngine($typesense);
    }

    protected function models(int|string ...$keys): EloquentCollection
    {
        return new EloquentCollection(array_map(static fn ($key) => new EngineDeleteStubModel($key), $keys));
    }
}

uses(EngineDeleteTestHelpers::class);

test('deletes all models with one filter request', function () {
    $this->engine()->delete($this->models(1, 2, 3));

    expect($this->collectionLookups)->toBe(1);
    expect($this->deleteQueries)->toBe([['filter_by' => 'id:[`1`,`2`,`3`]']]);
});

test('splits large deletes into batches', function () {
    $keys = range(1, TypesenseEngine::DELETE_BATCH_SIZE * 2 + 5);

    $this->engine()->delete($this->models(...$keys));

    expect($this->deleteQueries)->toHaveCount(3);
    expect(substr_count($this->deleteQueries[0]['filter_by'], ','))->toBe(TypesenseEngine::DELETE_BATCH_SIZE - 1);
    expect($this->deleteQueries[2]['filter_by'])->toBe('id:[`201`,`202`,`203`,`204`,`205`]');
});

test('quotes ids so they cannot change the filter', function () {
    $this->engine()->delete($this->models('a,b', 'x`] || id:[y'));

    expect($this->deleteQueries[0]['filter_by'])->toBe('id:[`a,b`,`x\`] || id:[y`]');
});

test('sends each id once', function () {
    $this->engine()->delete($this->models(7, 7, '7'));

    expect($this->deleteQueries)->toBe([['filter_by' => 'id:[`7`]']]);
});

test('does nothing for an empty collection', function () {
    $this->engine()->delete(new EloquentCollection());

    expect($this->collectionLookups)->toBe(0);
    expect($this->deleteQueries)->toBe([]);
});
