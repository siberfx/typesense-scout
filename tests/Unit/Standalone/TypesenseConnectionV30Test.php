<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use Siberfx\Typesense\Standalone\TypesenseConnection;
use Typesense\Client;
use Typesense\Collections;
use Typesense\MultiSearch;

/**
 * Server v30 options on the standalone connection: union multi-search and
 * collection cloning (optionally with documents).
 */

test('multi search sends body options next to searches', function () {
    $searches = [['collection' => 'posts', 'q' => '*'], ['collection' => 'users', 'q' => '*']];

    $multiSearch = $this->createMock(MultiSearch::class);
    $multiSearch->expects($this->once())
        ->method('perform')
        ->with(['union' => true, 'remove_duplicates' => false, 'searches' => $searches], ['per_page' => 5])
        ->willReturn(['hits' => []]);

    $client = $this->createStub(Client::class);
    $client->method('getMultiSearch')->willReturn($multiSearch);

    (new TypesenseConnection($client))->multiSearch($searches, ['per_page' => 5], ['union' => true, 'remove_duplicates' => false]);
});

test('union search sets the union flag', function () {
    $searches = [['collection' => 'posts', 'q' => '*']];

    $multiSearch = $this->createMock(MultiSearch::class);
    $multiSearch->expects($this->once())
        ->method('perform')
        ->with(['union' => true, 'searches' => $searches], [])
        ->willReturn(['hits' => []]);

    $client = $this->createStub(Client::class);
    $client->method('getMultiSearch')->willReturn($multiSearch);

    (new TypesenseConnection($client))->unionSearch($searches);
});

test('create collection passes query options', function () {
    $collections = $this->createMock(Collections::class);
    $collections->expects($this->once())
        ->method('create')
        ->with(['name' => 'books'], ['src_name' => 'templates'])
        ->willReturn(['name' => 'books']);

    $client = $this->createStub(Client::class);
    $client->method('getCollections')->willReturn($collections);

    (new TypesenseConnection($client))->createCollection(['name' => 'books'], ['src_name' => 'templates']);
});

test('clone collection copies documents when asked', function () {
    $collections = $this->createMock(Collections::class);
    $collections->expects($this->once())
        ->method('create')
        ->with(['name' => 'books_v2'], ['src_name' => 'books', 'copy_documents' => true])
        ->willReturn(['name' => 'books_v2']);

    $client = $this->createStub(Client::class);
    $client->method('getCollections')->willReturn($collections);

    (new TypesenseConnection($client))->cloneCollection('books', 'books_v2', copyDocuments: true);
});
