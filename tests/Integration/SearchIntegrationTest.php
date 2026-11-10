<?php

namespace Siberfx\Typesense\Tests\Integration;

uses(IntegrationServer::class);

beforeEach(function () {
    $this->connectToIntegrationServer();
});
/**
 * End-to-end collection / document / search flow against a real server.
 */

trait SearchIntegrationTestHelpers
{
    protected const COLLECTION = 'integration_books';
}

uses(SearchIntegrationTestHelpers::class);

afterEach(function () {
    if (isset($this->client)) {
        $this->dropCollection(self::COLLECTION);
    }
});

test('collection document and filtered search', function () {
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
    expect($byKeyword['found'])->toBe(1);

    // Comparison filter (the same syntax produced by where('rating', ['>', 4])).
    $byFilter = $documents->search([
        'q' => '*',
        'query_by' => 'title',
        'filter_by' => 'rating:>4',
    ]);
    expect($byFilter['found'])->toBe(1);
    expect($byFilter['hits'][0]['document']['id'])->toBe('1');
});
