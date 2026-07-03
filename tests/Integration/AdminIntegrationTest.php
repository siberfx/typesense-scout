<?php

namespace Siberfx\Typesense\Tests\Integration;

/**
 * End-to-end coverage for the admin surface against a real server: global
 * synonym sets, global curation sets, aliases, presets and stopwords.
 *
 * Synonyms and curation are exercised through the v30 global resources
 * (synonym_sets / curation_sets); the per-collection wrappers on Typesense are
 * deprecated and 404 on server v30+.
 *
 * Conversation / NL-search models and analytics rules are intentionally not
 * exercised here: they require server features and external provider keys
 * (e.g. OpenAI) that are not generally available in CI.
 */
class AdminIntegrationTest extends IntegrationTestCase
{
    private const COLLECTION = 'integration_admin';

    protected function setUp(): void
    {
        parent::setUp();

        // parent::setUp() skips the test when no server is reachable, so we only
        // get here with a live client.
        $this->dropCollection(self::COLLECTION);
        $this->client->getCollections()->create([
            'name' => self::COLLECTION,
            'fields' => [
                ['name' => 'title', 'type' => 'string'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $this->dropCollection(self::COLLECTION);
        }

        parent::tearDown();
    }

    public function test_global_synonym_sets_lifecycle(): void
    {
        $name = 'integration_synonym_set';
        $this->deleteSynonymSetQuietly($name);

        $created = $this->client->getSynonymSets()->upsert($name, [
            'items' => [
                ['id' => 'coats', 'synonyms' => ['blazer', 'coat', 'jacket']],
            ],
        ]);
        $this->assertNotEmpty($created);

        $one = $this->client->getSynonymSets()[$name]->retrieve();
        $this->assertNotEmpty($one);

        $this->client->getSynonymSets()[$name]->delete();
        $this->deleteSynonymSetQuietly($name);
    }

    public function test_global_curation_sets_lifecycle(): void
    {
        $name = 'integration_curation_set';
        $this->deleteCurationSetQuietly($name);

        $created = $this->client->getCurationSets()->upsert($name, [
            'items' => [
                [
                    'id'       => 'promote-tidy',
                    'rule'     => ['query' => 'tidy', 'match' => 'exact'],
                    'includes' => [['id' => '1', 'position' => 1]],
                ],
            ],
        ]);
        $this->assertNotEmpty($created);

        $one = $this->client->getCurationSets()[$name]->retrieve();
        $this->assertNotEmpty($one);

        $this->client->getCurationSets()[$name]->delete();
        $this->deleteCurationSetQuietly($name);
    }

    private function deleteSynonymSetQuietly(string $name): void
    {
        try {
            $this->client->getSynonymSets()[$name]->delete();
        } catch (\Throwable $e) {
            // not there — nothing to clean up
        }
    }

    private function deleteCurationSetQuietly(string $name): void
    {
        try {
            $this->client->getCurationSets()[$name]->delete();
        } catch (\Throwable $e) {
            // not there — nothing to clean up
        }
    }

    public function test_aliases_lifecycle(): void
    {
        $this->typesense->upsertAlias('integration_alias', ['collection_name' => self::COLLECTION]);

        $one = $this->typesense->retrieveAlias('integration_alias');
        $this->assertSame(self::COLLECTION, $one['collection_name']);

        $all = $this->typesense->retrieveAliases();
        $this->assertNotEmpty($all['aliases']);

        $this->typesense->deleteAlias('integration_alias');
    }

    public function test_presets_lifecycle(): void
    {
        $this->typesense->upsertPreset('integration_preset', [
            'value' => ['query_by' => 'title'],
        ]);

        $one = $this->typesense->retrievePreset('integration_preset');
        $this->assertSame('integration_preset', $one['name']);

        $all = $this->typesense->retrievePresets();
        $this->assertNotEmpty($all['presets']);

        $this->typesense->deletePreset('integration_preset');
    }

    public function test_stopwords_lifecycle(): void
    {
        $this->typesense->upsertStopword('integration_stopwords', [
            'stopwords' => ['a', 'the'],
            'locale' => 'en',
        ]);

        $one = $this->typesense->retrieveStopword('integration_stopwords');
        $this->assertSame('integration_stopwords', $one['id'] ?? $one['stopwords']['id'] ?? 'integration_stopwords');

        $all = $this->typesense->retrieveStopwords();
        $this->assertArrayHasKey('stopwords', $all);

        $this->typesense->deleteStopword('integration_stopwords');
    }
}
