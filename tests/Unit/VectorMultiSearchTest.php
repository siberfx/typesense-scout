<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Engines\TypesenseEngine;
use Siberfx\Typesense\Typesense;
use Typesense\Collection;
use Typesense\Documents;
use Typesense\Exceptions\RequestMalformed;

/**
 * Vector queries must travel in a POST body (multi-search), not in the GET
 * query string of a regular search, where long embeddings exceed the limit.
 */
class VectorMultiSearchTest extends TestCase
{
    private function builder(string $query): Builder
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

    private function typesense(Documents $documents): Typesense
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getDocuments')->willReturn($documents);

        $typesense = $this->createMock(Typesense::class);
        $typesense->method('getCollectionIndex')->willReturn($collection);

        return $typesense;
    }

    public function test_plain_search_uses_documents_endpoint(): void
    {
        $documents = $this->createMock(Documents::class);
        $documents->expects($this->once())->method('search')->willReturn(['found' => 0, 'hits' => []]);

        $typesense = $this->typesense($documents);
        $typesense->expects($this->never())->method('multiSearch');

        (new TypesenseEngine($typesense))->search($this->builder('shoes'));
    }

    public function test_vector_search_is_sent_through_multi_search(): void
    {
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
        $engine->nearestNeighbors('embedding', [0.1, 0.2]);

        $result = $engine->search($this->builder('*'));

        $this->assertSame(1, $result['found']);
        $this->assertSame(['7'], $engine->mapIds($result)->all());
    }

    public function test_multi_search_error_entry_is_thrown(): void
    {
        $typesense = $this->typesense($this->createStub(Documents::class));
        $typesense->expects($this->once())->method('multiSearch')->willReturn([
            'results' => [['code' => 400, 'error' => 'Field `embedding` not found.']],
        ]);

        $engine = new TypesenseEngine($typesense);
        $engine->vectorQuery('embedding:([0.1], k:3)');

        $this->expectException(RequestMalformed::class);
        $this->expectExceptionMessage('Field `embedding` not found.');

        $engine->search($this->builder('*'));
    }

    public function test_common_vector_query_moves_into_each_multi_search_body(): void
    {
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

        $engine = new TypesenseEngine($typesense);
        $engine->vectorQuery('embedding:([0.1], k:3)');
        $engine->searchMulti([
            ['collection' => 'a'],
            ['collection' => 'b', 'vector_query' => 'own:([0.9], k:1)'],
        ]);

        $engine->search($this->builder('*'));
    }
}
