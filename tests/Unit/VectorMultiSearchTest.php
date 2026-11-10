<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use Siberfx\Typesense\Engines\TypesenseEngine;
use Siberfx\Typesense\Typesense;
use Typesense\Collection;
use Typesense\Documents;
use Typesense\Exceptions\RequestMalformed;

/**
 * Vector queries must travel in a POST body (multi-search), not in the GET
 * query string of a regular search, where long embeddings exceed the limit.
 */

trait VectorMultiSearchTestHelpers
{
    protected function builder(string $query): Builder
    {
        $model = new class {
            public function typesenseQueryBy(): array
            {
                return ['name'];
            }

            public function searchableAs(): string
            {
                return 'products';
            }
        };

        return new Builder($model, $query);
    }

    protected function typesense(Documents $documents): Typesense
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getDocuments')->willReturn($documents);

        $typesense = $this->createMock(Typesense::class);
        $typesense->method('getCollectionIndex')->willReturn($collection);
        $typesense->method('withCollection')->willReturnCallback(fn ($model, $operation) => $operation($collection));

        return $typesense;
    }
}

uses(VectorMultiSearchTestHelpers::class);

test('plain search uses documents endpoint', function () {
    $documents = $this->createMock(Documents::class);
    $documents->expects($this->once())->method('search')->willReturn(['found' => 0, 'hits' => []]);

    $typesense = $this->typesense($documents);
    $typesense->expects($this->never())->method('multiSearch');

    (new TypesenseEngine($typesense))->search($this->builder('shoes'));
});

test('vector search is sent through multi search', function () {
    $documents = $this->createMock(Documents::class);
    $documents->expects($this->never())->method('search');

    $typesense = $this->typesense($documents);
    $typesense->expects($this->once())
        ->method('multiSearch')
        ->with(
            $this->callback(function (array $body): bool {
                $search = $body['searches'][0];

                return count($body['searches']) === 1
                    && $search['collection'] === 'products'
                    && $search['vector_query'] === 'embedding:([0.1, 0.2], k:10)'
                    && $search['q'] === '*';
            }),
            []
        )
        ->willReturn(['results' => [['found' => 1, 'hits' => [['document' => ['id' => '7']]]]]]);

    $engine = new TypesenseEngine($typesense);
    $builder = $this->builder('*')->nearestNeighbors('embedding', [0.1, 0.2]);

    $result = $engine->search($builder);

    expect($result['found'])->toBe(1);
    expect($engine->mapIds($result)->all())->toBe(['7']);
});

test('multi search error entry is thrown', function () {
    expect(function () {
        $typesense = $this->typesense($this->createStub(Documents::class));
        $typesense->expects($this->once())->method('multiSearch')->willReturn([
            'results' => [['code' => 400, 'error' => 'Field `embedding` not found.']],
        ]);

        $builder = $this->builder('*')->vectorQuery('embedding:([0.1], k:3)');


        (new TypesenseEngine($typesense))->search($builder);
    })->toThrow(RequestMalformed::class, 'Field `embedding` not found.');
});

test('common vector query moves into each multi search body', function () {
    $typesense = $this->typesense($this->createStub(Documents::class));
    $typesense->expects($this->once())
        ->method('multiSearch')
        ->with(
            $this->callback(fn (array $body): bool => $body['searches'] === [
                ['collection' => 'a', 'vector_query' => 'embedding:([0.1], k:3)'],
                ['collection' => 'b', 'vector_query' => 'own:([0.9], k:1)'],
            ]),
            $this->callback(fn (array $common): bool => !isset($common['vector_query']) && $common['q'] === '*')
        )
        ->willReturn(['results' => []]);

    $builder = $this->builder('*')
        ->vectorQuery('embedding:([0.1], k:3)')
        ->searchMulti([
            ['collection' => 'a'],
            ['collection' => 'b', 'vector_query' => 'own:([0.9], k:1)'],
        ]);

    (new TypesenseEngine($typesense))->search($builder);
});
