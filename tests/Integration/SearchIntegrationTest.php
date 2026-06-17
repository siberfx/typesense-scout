<?php

namespace Siberfx\Typesense\Tests\Integration;

/**
 * End-to-end collection / document / search flow against a real server.
 */
class SearchIntegrationTest extends IntegrationTestCase
{
    private const COLLECTION = 'integration_books';

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $this->dropCollection(self::COLLECTION);
        }

        parent::tearDown();
    }

    public function test_collection_document_and_filtered_search(): void
    {
        $this->dropCollection(self::COLLECTION);

        $this->client->getCollections()->create([
            'name' => self::COLLECTION,
            'fields' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'rating', 'type' => 'int32'],
            ],
        ]);

        $documents = $this->client->getCollections()[self::COLLECTION]->getDocuments();
        $documents->create(['id' => '1', 'title' => 'Typesense Guide', 'rating' => 5]);
        $documents->create(['id' => '2', 'title' => 'Laravel Scout Handbook', 'rating' => 3]);

        // Keyword search.
        $byKeyword = $documents->search(['q' => 'typesense', 'query_by' => 'title']);
        $this->assertSame(1, $byKeyword['found']);

        // Comparison filter (the same syntax produced by where('rating', ['>', 4])).
        $byFilter = $documents->search([
            'q' => '*',
            'query_by' => 'title',
            'filter_by' => 'rating:>4',
        ]);
        $this->assertSame(1, $byFilter['found']);
        $this->assertSame('1', $byFilter['hits'][0]['document']['id']);
    }
}
