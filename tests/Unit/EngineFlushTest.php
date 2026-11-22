<?php

namespace Siberfx\Typesense\Tests\Unit;

use Siberfx\Typesense\Engines\TypesenseEngine;
use Siberfx\Typesense\Typesense;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\RequestUnauthorized;

trait EngineFlushTestHelpers
{
    protected function model(): object
    {
        return new class {
            public function searchableAs(): string
            {
                return 'todos';
            }
        };
    }
}

uses(EngineFlushTestHelpers::class);

test('flush drops the collection without looking it up first', function () {
    $typesense = $this->createMock(Typesense::class);
    $typesense->expects($this->never())->method('getCollectionIndex');
    $typesense->expects($this->never())->method('withCollection');
    $typesense->expects($this->once())->method('deleteCollection')->with('todos')->willReturn(['name' => 'todos']);

    (new TypesenseEngine($typesense))->flush($this->model());
});

test('flush of a missing collection is a no op', function () {
    $typesense = $this->createMock(Typesense::class);
    $typesense->method('deleteCollection')->willThrowException(new ObjectNotFound('Not found.'));
    $typesense->expects($this->once())->method('forgetCollection')->with('todos');

    (new TypesenseEngine($typesense))->flush($this->model());
});

test('flush surfaces other errors', function () {
    $typesense = $this->createStub(Typesense::class);
    $typesense->method('deleteCollection')->willThrowException(new RequestUnauthorized('Forbidden.'));

    expect(fn () => (new TypesenseEngine($typesense))->flush($this->model()))
        ->toThrow(RequestUnauthorized::class);
});
