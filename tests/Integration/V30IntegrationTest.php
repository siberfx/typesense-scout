<?php

namespace Siberfx\Typesense\Tests\Integration;

use Siberfx\Typesense\Standalone\TypesenseConnection;

uses(IntegrationServer::class);

beforeEach(function () {
    $this->connectToIntegrationServer();
});
/**
 * Server v30 features end to end: union search, collection cloning with
 * documents, per-search synonym sets and the curation set / API key helpers.
 */

trait V30IntegrationTestHelpers
{
    protected const SCHEMA_FIELDS = [
        ['name' => 'title', 'type' => 'string'],
    ];

    protected function connection(): TypesenseConnection
    {
        return new TypesenseConnection($this->client);
    }

    protected function seed(string $collection, array $titles): void
    {
        $this->dropCollection($collection);
        $this->client->getCollections()->create(['name' => $collection, 'fields' => self::SCHEMA_FIELDS]);

        foreach ($titles as $i => $title) {
            $this->client->getCollections()[$collection]->getDocuments()->create(['id' => (string) $i, 'title' => $title]);
        }
    }
}

uses(V30IntegrationTestHelpers::class);

test('union search merges hits from every search', function () {
    $this->seed('v30_union_a', ['red shoe']);
    $this->seed('v30_union_b', ['blue shoe', 'green shoe']);

    try {
        $result = $this->connection()->unionSearch([
            ['collection' => 'v30_union_a', 'q' => 'shoe', 'query_by' => 'title'],
            ['collection' => 'v30_union_b', 'q' => 'shoe', 'query_by' => 'title'],
        ]);

        expect($result['found'])->toBe(3);
        expect($result['hits'])->toHaveCount(3);
    } finally {
        $this->dropCollection('v30_union_a');
        $this->dropCollection('v30_union_b');
    }
});

test('clone collection copies schema and documents', function () {
    $this->seed('v30_clone_src', ['one', 'two']);
    $this->dropCollection('v30_clone_dst');

    try {
        $this->connection()->cloneCollection('v30_clone_src', 'v30_clone_dst', copyDocuments: true);

        $result = $this->connection()->search('v30_clone_dst', ['q' => '*']);

        expect($result['found'])->toBe(2);
    } finally {
        $this->dropCollection('v30_clone_src');
        $this->dropCollection('v30_clone_dst');
    }
});

test('synonym set applies per search', function () {
    $this->seed('v30_synonyms', ['winter jacket']);
    $this->typesense->upsertSynonymSet('v30_clothing', [
        'items' => [['id' => 'coat', 'synonyms' => ['coat', 'jacket']]],
    ]);

    try {
        $params = ['q' => 'coat', 'query_by' => 'title'];

        expect($this->connection()->search('v30_synonyms', $params)['found'])->toBe(0);
        expect($this->connection()->search('v30_synonyms', $params + ['synonym_sets' => 'v30_clothing'])['found'])->toBe(1);
        expect($this->typesense->retrieveSynonymSet('v30_clothing')['name'])->toBe('v30_clothing');
    } finally {
        $this->typesense->deleteSynonymSet('v30_clothing');
        $this->dropCollection('v30_synonyms');
    }
});

test('curation set item helpers round trip', function () {
    $this->typesense->upsertCurationSet('v30_curation', ['items' => []]);

    try {
        $this->typesense->upsertCurationSetItem('v30_curation', 'pin', [
            'rule' => ['query' => 'apple', 'match' => 'exact'],
            'includes' => [['id' => '42', 'position' => 1]],
        ]);

        expect($this->typesense->retrieveCurationSetItem('v30_curation', 'pin')['id'])->toBe('pin');
        expect($this->typesense->retrieveCurationSetItems('v30_curation'))->toHaveCount(1);

        $this->typesense->deleteCurationSetItem('v30_curation', 'pin');

        expect($this->typesense->retrieveCurationSetItems('v30_curation'))->toHaveCount(0);
    } finally {
        $this->typesense->deleteCurationSet('v30_curation');
    }
});

test('api key helpers round trip', function () {
    $key = $this->typesense->createApiKey([
        'description' => 'v30 integration',
        'actions' => ['documents:search'],
        'collections' => ['*'],
    ]);

    expect($this->typesense->retrieveApiKey($key['id'])['description'])->toBe('v30 integration');
    expect(array_column($this->typesense->retrieveApiKeys()['keys'], 'id'))->toContain($key['id']);

    $this->typesense->deleteApiKey($key['id']);

    expect(array_column($this->typesense->retrieveApiKeys()['keys'], 'id'))->not->toContain($key['id']);
});
