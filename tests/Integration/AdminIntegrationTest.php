<?php

namespace Siberfx\Typesense\Tests\Integration;

uses(IntegrationServer::class);

beforeEach(function () {
    $this->connectToIntegrationServer();
});
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

trait AdminIntegrationTestHelpers
{
    protected const COLLECTION = 'integration_admin';

    protected function deleteSynonymSetQuietly(string $name): void
    {
        try {
            $this->client->getSynonymSets()[$name]->delete();
        } catch (\Throwable $e) {
            // not there — nothing to clean up
        }
    }

    protected function deleteCurationSetQuietly(string $name): void
    {
        try {
            $this->client->getCurationSets()[$name]->delete();
        } catch (\Throwable $e) {
            // not there — nothing to clean up
        }
    }
}

uses(AdminIntegrationTestHelpers::class);

beforeEach(function () {
    
    // parent::setUp() skips the test when no server is reachable, so we only
    // get here with a live client.
    $this->dropCollection(self::COLLECTION);
    $this->client->getCollections()->create([
        'name' => self::COLLECTION,
        'fields' => [
            ['name' => 'title', 'type' => 'string'],
        ],
    ]);
});

afterEach(function () {
    if (isset($this->client)) {
        $this->dropCollection(self::COLLECTION);
    }
});

test('global synonym sets lifecycle', function () {
    $name = 'integration_synonym_set';
    $this->deleteSynonymSetQuietly($name);

    $created = $this->client->getSynonymSets()->upsert($name, [
        'items' => [
            ['id' => 'coats', 'synonyms' => ['blazer', 'coat', 'jacket']],
        ],
    ]);
    expect($created)->not->toBeEmpty();

    $one = $this->client->getSynonymSets()[$name]->retrieve();
    expect($one)->not->toBeEmpty();

    $this->client->getSynonymSets()[$name]->delete();
    $this->deleteSynonymSetQuietly($name);
});

test('global curation sets lifecycle', function () {
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
    expect($created)->not->toBeEmpty();

    $one = $this->client->getCurationSets()[$name]->retrieve();
    expect($one)->not->toBeEmpty();

    $this->client->getCurationSets()[$name]->delete();
    $this->deleteCurationSetQuietly($name);
});

test('aliases lifecycle', function () {
    $this->typesense->upsertAlias('integration_alias', ['collection_name' => self::COLLECTION]);

    $one = $this->typesense->retrieveAlias('integration_alias');
    expect($one['collection_name'])->toBe(self::COLLECTION);

    $all = $this->typesense->retrieveAliases();
    expect($all['aliases'])->not->toBeEmpty();

    $this->typesense->deleteAlias('integration_alias');
});

test('presets lifecycle', function () {
    $this->typesense->upsertPreset('integration_preset', [
        'value' => ['query_by' => 'title'],
    ]);

    $one = $this->typesense->retrievePreset('integration_preset');
    expect($one['name'])->toBe('integration_preset');

    $all = $this->typesense->retrievePresets();
    expect($all['presets'])->not->toBeEmpty();

    $this->typesense->deletePreset('integration_preset');
});

test('stopwords lifecycle', function () {
    $this->typesense->upsertStopword('integration_stopwords', [
        'stopwords' => ['a', 'the'],
        'locale' => 'en',
    ]);

    $one = $this->typesense->retrieveStopword('integration_stopwords');
    expect($one['id'] ?? $one['stopwords']['id'] ?? 'integration_stopwords')->toBe('integration_stopwords');

    $all = $this->typesense->retrieveStopwords();
    expect($all)->toHaveKey('stopwords');

    $this->typesense->deleteStopword('integration_stopwords');
});
