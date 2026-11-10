<?php

namespace Siberfx\Typesense\Tests\Unit;

use Siberfx\Typesense\Typesense;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Exceptions\ObjectNotFound;

/**
 * A missing collection must be created under the same name it is looked up
 * by (ported from laravel/scout 11.9, #1013).
 */

trait CollectionCreationTestHelpers
{
    protected function collections(Collection $collection): Collections
    {
        return new class($collection) extends Collections
        {
            public array $created = [];

            public array $accessed = [];

            public function __construct(protected Collection $collection)
            {
                //
            }

            public function __get($collectionName)
            {
                $this->accessed[] = $collectionName;

                return $this->collection;
            }

            public function create(array $schema, array $options = []): array
            {
                $this->created[] = $schema;

                return $schema;
            }
        };
    }

    protected function typesense(Collections $collections): Typesense
    {
        $client = $this->createStub(Client::class);
        $client->method('getCollections')->willReturn($collections);

        return new Typesense($client);
    }

    protected function missingCollection(): Collection
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('retrieve')->willThrowException(new ObjectNotFound('Not Found'));

        return $collection;
    }

    protected function model(array $schema): object
    {
        return new class($schema)
        {
            public function __construct(private array $schema)
            {
                //
            }

            public function searchableAs(): string
            {
                return 'posts_v2';
            }

            public function getCollectionSchema(): array
            {
                return $this->schema;
            }
        };
    }
}

uses(CollectionCreationTestHelpers::class);

test('missing collection without schema name is created using searchable as', function () {
    $collections = $this->collections($this->missingCollection());

    $this->typesense($collections)->getCollectionIndex($this->model(['fields' => []]));

    expect($collections->accessed)->toBe(['posts_v2', 'posts_v2']);
    expect($collections->created[0]['name'])->toBe('posts_v2');
});

test('explicit schema name is kept', function () {
    $collections = $this->collections($this->missingCollection());

    $this->typesense($collections)->getCollectionIndex($this->model(['name' => 'custom', 'fields' => []]));

    expect($collections->created[0]['name'])->toBe('custom');
});

test('existing collection is not recreated', function () {
    $collections = $this->collections($this->createStub(Collection::class));

    $this->typesense($collections)->getCollectionIndex($this->model(['fields' => []]));

    expect($collections->created)->toBe([]);
});
