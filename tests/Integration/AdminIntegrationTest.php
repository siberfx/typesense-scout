<?php

namespace Siberfx\Typesense\Tests\Integration;

/**
 * End-to-end coverage for the admin wrappers on the Typesense class against a
 * real server: synonyms, curation/overrides, aliases, presets and stopwords.
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

    public function test_synonyms_lifecycle(): void
    {
        $this->typesense->upsertSynonym(self::COLLECTION, 'coat-synonyms', [
            'synonyms' => ['blazer', 'coat', 'jacket'],
        ]);

        $all = $this->typesense->retrieveSynonyms(self::COLLECTION);
        $this->assertNotEmpty($all['synonyms']);

        $one = $this->typesense->retrieveSynonym(self::COLLECTION, 'coat-synonyms');
        $this->assertSame('coat-synonyms', $one['id']);

        $deleted = $this->typesense->deleteSynonym(self::COLLECTION, 'coat-synonyms');
        $this->assertSame('coat-synonyms', $deleted['id']);
    }

    public function test_overrides_lifecycle(): void
    {
        $this->typesense->upsertOverride(self::COLLECTION, 'promote-tidy', [
            'rule' => ['query' => 'tidy', 'match' => 'exact'],
            'includes' => [['id' => '1', 'position' => 1]],
        ]);

        $all = $this->typesense->retrieveOverrides(self::COLLECTION);
        $this->assertNotEmpty($all['overrides']);

        $one = $this->typesense->retrieveOverride(self::COLLECTION, 'promote-tidy');
        $this->assertSame('promote-tidy', $one['id']);

        $deleted = $this->typesense->deleteOverride(self::COLLECTION, 'promote-tidy');
        $this->assertSame('promote-tidy', $deleted['id']);
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
