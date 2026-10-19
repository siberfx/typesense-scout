<?php

namespace Siberfx\Typesense\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Typesense;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Exceptions\ObjectNotFound;

/**
 * A missing collection must be created under the same name it is looked up
 * by (ported from laravel/scout 11.9, #1013).
 */
class CollectionCreationTest extends TestCase
{
    private function collections(Collection $collection): Collections
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

    private function typesense(Collections $collections): Typesense
    {
        $client = $this->createStub(Client::class);
        $client->method('getCollections')->willReturn($collections);

        return new Typesense($client);
    }

    private function missingCollection(): Collection
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('retrieve')->willThrowException(new ObjectNotFound('Not Found'));

        return $collection;
    }

    private function model(array $schema): object
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

    public function test_missing_collection_without_schema_name_is_created_using_searchable_as(): void
    {
        $collections = $this->collections($this->missingCollection());

        $this->typesense($collections)->getCollectionIndex($this->model(['fields' => []]));

        $this->assertSame(['posts_v2', 'posts_v2'], $collections->accessed);
        $this->assertSame('posts_v2', $collections->created[0]['name']);
    }

    public function test_explicit_schema_name_is_kept(): void
    {
        $collections = $this->collections($this->missingCollection());

        $this->typesense($collections)->getCollectionIndex($this->model(['name' => 'custom', 'fields' => []]));

        $this->assertSame('custom', $collections->created[0]['name']);
    }

    public function test_existing_collection_is_not_recreated(): void
    {
        $collections = $this->collections($this->createStub(Collection::class));

        $this->typesense($collections)->getCollectionIndex($this->model(['fields' => []]));

        $this->assertSame([], $collections->created);
    }
}
