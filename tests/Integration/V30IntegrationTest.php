<?php

namespace Siberfx\Typesense\Tests\Integration;

use Siberfx\Typesense\Standalone\TypesenseConnection;

/**
 * Server v30 features end to end: union search, collection cloning with
 * documents, per-search synonym sets and the curation set / API key helpers.
 */
class V30IntegrationTest extends IntegrationTestCase
{
    private const SCHEMA_FIELDS = [
        ['name' => 'title', 'type' => 'string'],
    ];

    private function connection(): TypesenseConnection
    {
        return new TypesenseConnection($this->client);
    }

    private function seed(string $collection, array $titles): void
    {
        $this->dropCollection($collection);
        $this->client->getCollections()->create(['name' => $collection, 'fields' => self::SCHEMA_FIELDS]);

        foreach ($titles as $i => $title) {
            $this->client->getCollections()[$collection]->getDocuments()->create(['id' => (string) $i, 'title' => $title]);
        }
    }

    public function test_union_search_merges_hits_from_every_search(): void
    {
        $this->seed('v30_union_a', ['red shoe']);
        $this->seed('v30_union_b', ['blue shoe', 'green shoe']);

        try {
            $result = $this->connection()->unionSearch([
                ['collection' => 'v30_union_a', 'q' => 'shoe', 'query_by' => 'title'],
                ['collection' => 'v30_union_b', 'q' => 'shoe', 'query_by' => 'title'],
            ]);

            $this->assertSame(3, $result['found']);
            $this->assertCount(3, $result['hits']);
        } finally {
            $this->dropCollection('v30_union_a');
            $this->dropCollection('v30_union_b');
        }
    }

    public function test_clone_collection_copies_schema_and_documents(): void
    {
        $this->seed('v30_clone_src', ['one', 'two']);
        $this->dropCollection('v30_clone_dst');

        try {
            $this->connection()->cloneCollection('v30_clone_src', 'v30_clone_dst', copyDocuments: true);

            $result = $this->connection()->search('v30_clone_dst', ['q' => '*']);

            $this->assertSame(2, $result['found']);
        } finally {
            $this->dropCollection('v30_clone_src');
            $this->dropCollection('v30_clone_dst');
        }
    }

    public function test_synonym_set_applies_per_search(): void
    {
        $this->seed('v30_synonyms', ['winter jacket']);
        $this->typesense->upsertSynonymSet('v30_clothing', [
            'items' => [['id' => 'coat', 'synonyms' => ['coat', 'jacket']]],
        ]);

        try {
            $params = ['q' => 'coat', 'query_by' => 'title'];

            $this->assertSame(0, $this->connection()->search('v30_synonyms', $params)['found']);
            $this->assertSame(1, $this->connection()->search('v30_synonyms', $params + ['synonym_sets' => 'v30_clothing'])['found']);
            $this->assertSame('v30_clothing', $this->typesense->retrieveSynonymSet('v30_clothing')['name']);
        } finally {
            $this->typesense->deleteSynonymSet('v30_clothing');
            $this->dropCollection('v30_synonyms');
        }
    }

    public function test_curation_set_item_helpers_round_trip(): void
    {
        $this->typesense->upsertCurationSet('v30_curation', ['items' => []]);

        try {
            $this->typesense->upsertCurationSetItem('v30_curation', 'pin', [
                'rule' => ['query' => 'apple', 'match' => 'exact'],
                'includes' => [['id' => '42', 'position' => 1]],
            ]);

            $this->assertSame('pin', $this->typesense->retrieveCurationSetItem('v30_curation', 'pin')['id']);
            $this->assertCount(1, $this->typesense->retrieveCurationSetItems('v30_curation'));

            $this->typesense->deleteCurationSetItem('v30_curation', 'pin');

            $this->assertCount(0, $this->typesense->retrieveCurationSetItems('v30_curation'));
        } finally {
            $this->typesense->deleteCurationSet('v30_curation');
        }
    }

    public function test_api_key_helpers_round_trip(): void
    {
        $key = $this->typesense->createApiKey([
            'description' => 'v30 integration',
            'actions' => ['documents:search'],
            'collections' => ['*'],
        ]);

        $this->assertSame('v30 integration', $this->typesense->retrieveApiKey($key['id'])['description']);
        $this->assertContains($key['id'], array_column($this->typesense->retrieveApiKeys()['keys'], 'id'));

        $this->typesense->deleteApiKey($key['id']);

        $this->assertNotContains($key['id'], array_column($this->typesense->retrieveApiKeys()['keys'], 'id'));
    }
}
