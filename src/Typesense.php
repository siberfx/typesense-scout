<?php

namespace Siberfx\Typesense;

use Illuminate\Support\Facades\Config;
use Laravel\Scout\EngineManager;
use Typesense\Exceptions\TypesenseClientError;
use Siberfx\Typesense\Classes\TypesenseDocumentIndexResponse;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Document;
use Laravel\Scout\Builder;
use Siberfx\Typesense\Mixin\BuilderMixin;
use Typesense\Exceptions\ObjectNotFound;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * Class Typesense
 *
 * @package Siberfx\Typesense
 * @date    4/5/20
 *
 * @author  Selim Görmüş <info@siberfx.com>
 */
class Typesense
{
    /**
     * @var \Typesense\Client
     */
    private Client $client;

    /**
     * Typesense constructor.
     *
     * @param \Typesense\Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return \Typesense\Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @param $key
     * @return void
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \ReflectionException
     * @throws \Typesense\Exceptions\ConfigError
     */
    public function setScopedApiKey($key): void
    {
        $config = Config::get('scout.typesense.client-settings');
        $config['api_key'] = $key;
        $client = new Client($config);

        app()[EngineManager::class]->extend('typesense', static function () use ($client) {
            return new TypesenseEngine(new Typesense($client));
        });
        Builder::mixin(app()->make(BuilderMixin::class));
    }

    /**
     * @param $model
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return \Typesense\Collection
     */
    private function getOrCreateCollectionFromModel($model): Collection
    {
        $index = $this->client->getCollections()->{$model->searchableAs()};

        try {
            $index->retrieve();

            return $index;
        } catch (ObjectNotFound $exception) {
            $this->client->getCollections()
                         ->create($model->getCollectionSchema());

            return $this->client->getCollections()->{$model->searchableAs()};
        }
    }

    /**
     * @param $model
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return \Typesense\Collection
     */
    public function getCollectionIndex($model): Collection
    {
        return $this->getOrCreateCollectionFromModel($model);
    }

    /**
     * @param \Typesense\Collection $collectionIndex
     * @param                       $array
     *
     * @throws \Typesense\Exceptions\ObjectNotFound
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return \Siberfx\Typesense\Classes\TypesenseDocumentIndexResponse
     */
    public function upsertDocument(Collection $collectionIndex, $array): TypesenseDocumentIndexResponse
    {
        /**
         * @var $document Document
         */
        $document = $collectionIndex->getDocuments()[$array['id']];

        try {
            $document->retrieve();
            $document->delete();

            return new TypesenseDocumentIndexResponse(200, true, null, $collectionIndex->getDocuments()
                                                                                       ->create($array));
        } catch (ObjectNotFound) {
            return new TypesenseDocumentIndexResponse(200, true, null, $collectionIndex->getDocuments()
                                                                                       ->create($array));
        }
    }

    /**
     * @param \Typesense\Collection $collectionIndex
     * @param                       $modelId
     *
     * @throws \Typesense\Exceptions\ObjectNotFound
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return array
     */
    public function deleteDocument(Collection $collectionIndex, $modelId): array
    {
        /**
         * @var $document Document
         */
        $document = $collectionIndex->getDocuments()[(string) $modelId];

        try {
            $document->retrieve();

            return $document->delete();
        } catch (\Exception $exception) {
            return [];
        }
    }

    /**
     * @param \Typesense\Collection $collectionIndex
     * @param array                 $query
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return array
     */
    public function deleteDocuments(Collection $collectionIndex, array $query): array
    {
        return $collectionIndex->getDocuments()
                               ->delete($query);
    }

    /**
     * @param \Typesense\Collection $collectionIndex
     * @param                       $documents
     * @param string                $action
     *
     * @throws \JsonException
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return \Illuminate\Support\Collection
     */
    public function importDocuments(Collection $collectionIndex, $documents, string $action = 'upsert'): \Illuminate\Support\Collection
    {
        $importedDocuments = $collectionIndex->getDocuments()
                                             ->import($documents, ['action' => $action]);

        $result = [];
        foreach ($importedDocuments as $importedDocument) {
            if (!$importedDocument['success']) {
                throw new TypesenseClientError("Error importing document: {$importedDocument['error']}");
            }

            $result[] = new TypesenseDocumentIndexResponse($importedDocument['code'] ?? 0, $importedDocument['success'], $importedDocument['error'] ?? null, json_decode($importedDocument['document'] ?? '[]', true, 512, JSON_THROW_ON_ERROR));
        }

        return collect($result);
    }

    /**
     * @param string $collectionName
     *
     * @throws \Typesense\Exceptions\ObjectNotFound
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return array
     */
    public function deleteCollection(string $collectionName): array
    {
        return $this->client->getCollections()->{$collectionName}->delete();
    }

    /**
     * @param array $searchRequests
     * @param array $commonSearchParams
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function multiSearch(array $searchRequests, array $commonSearchParams): array
    {
        return $this->client->multiSearch->perform($searchRequests, $commonSearchParams);
    }

    /**
     * Generate a Typesense scoped search API key.
     *
     * The returned key embeds the given search parameters (e.g. a `filter_by`
     * for multi-tenant isolation, or an `expires_at`) and is computed locally
     * via HMAC — no API call is made. Use the result as the `api_key` for
     * search-only clients.
     *
     * @param string $searchKey  A parent search-only API key.
     * @param array  $parameters Embedded search parameters, e.g.
     *                           ['filter_by' => 'company_id:42', 'expires_at' => ...].
     *
     * @return string
     * @throws \JsonException
     */
    public function generateScopedSearchKey(string $searchKey, array $parameters): string
    {
        return $this->client->getKeys()->generateScopedSearchKey($searchKey, $parameters);
    }

    /**
     * Create a new Typesense API key.
     *
     * @param array $schema The key schema, e.g.
     *                      ['description' => '...', 'actions' => ['documents:search'], 'collections' => ['*']].
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function createApiKey(array $schema): array
    {
        return $this->client->getKeys()->create($schema);
    }

    /*
    |--------------------------------------------------------------------------
    | Synonyms (per collection)
    |--------------------------------------------------------------------------
    */

    /**
     * Create or update a synonym for a collection.
     *
     * @param string $collectionName
     * @param string $synonymId
     * @param array  $config e.g. ['synonyms' => ['blazer', 'coat', 'jacket']].
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function upsertSynonym(string $collectionName, string $synonymId, array $config): array
    {
        return $this->client->getCollections()->{$collectionName}->getSynonyms()->upsert($synonymId, $config);
    }

    /**
     * Retrieve all synonyms for a collection.
     *
     * @param string $collectionName
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveSynonyms(string $collectionName): array
    {
        return $this->client->getCollections()->{$collectionName}->getSynonyms()->retrieve();
    }

    /**
     * Retrieve a single synonym from a collection.
     *
     * @param string $collectionName
     * @param string $synonymId
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveSynonym(string $collectionName, string $synonymId): array
    {
        return $this->client->getCollections()->{$collectionName}->getSynonyms()[$synonymId]->retrieve();
    }

    /**
     * Delete a synonym from a collection.
     *
     * @param string $collectionName
     * @param string $synonymId
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function deleteSynonym(string $collectionName, string $synonymId): array
    {
        return $this->client->getCollections()->{$collectionName}->getSynonyms()[$synonymId]->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Overrides / Curation (per collection)
    |--------------------------------------------------------------------------
    */

    /**
     * Create or update a curation override for a collection.
     *
     * @param string $collectionName
     * @param string $overrideId
     * @param array  $config e.g. ['rule' => [...], 'includes' => [...], 'excludes' => [...]].
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function upsertOverride(string $collectionName, string $overrideId, array $config): array
    {
        return $this->client->getCollections()->{$collectionName}->getOverrides()->upsert($overrideId, $config);
    }

    /**
     * Retrieve all curation overrides for a collection.
     *
     * @param string $collectionName
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveOverrides(string $collectionName): array
    {
        return $this->client->getCollections()->{$collectionName}->getOverrides()->retrieve();
    }

    /**
     * Retrieve a single curation override from a collection.
     *
     * @param string $collectionName
     * @param string $overrideId
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveOverride(string $collectionName, string $overrideId): array
    {
        return $this->client->getCollections()->{$collectionName}->getOverrides()[$overrideId]->retrieve();
    }

    /**
     * Delete a curation override from a collection.
     *
     * @param string $collectionName
     * @param string $overrideId
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function deleteOverride(string $collectionName, string $overrideId): array
    {
        return $this->client->getCollections()->{$collectionName}->getOverrides()[$overrideId]->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Collection Aliases
    |--------------------------------------------------------------------------
    */

    /**
     * Create or update a collection alias.
     *
     * @param string $name
     * @param array  $mapping e.g. ['collection_name' => 'products_v2'].
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function upsertAlias(string $name, array $mapping): array
    {
        return $this->client->getAliases()->upsert($name, $mapping);
    }

    /**
     * Retrieve all collection aliases.
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveAliases(): array
    {
        return $this->client->getAliases()->retrieve();
    }

    /**
     * Retrieve a single collection alias.
     *
     * @param string $name
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveAlias(string $name): array
    {
        return $this->client->getAliases()[$name]->retrieve();
    }

    /**
     * Delete a collection alias.
     *
     * @param string $name
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function deleteAlias(string $name): array
    {
        return $this->client->getAliases()[$name]->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Analytics Rules
    |--------------------------------------------------------------------------
    */

    /**
     * Create or update an analytics rule.
     *
     * @param string $ruleName
     * @param array  $params e.g. ['type' => 'popular_queries', 'params' => [...]].
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function upsertAnalyticsRule(string $ruleName, array $params): array
    {
        return $this->client->getAnalytics()->rules()->upsert($ruleName, $params);
    }

    /**
     * Retrieve all analytics rules.
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveAnalyticsRules(): array
    {
        return $this->client->getAnalytics()->rules()->retrieve();
    }

    /**
     * Retrieve a single analytics rule.
     *
     * @param string $ruleName
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveAnalyticsRule(string $ruleName): array
    {
        return $this->client->getAnalytics()->rules()[$ruleName]->retrieve();
    }

    /**
     * Delete an analytics rule.
     *
     * @param string $ruleName
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function deleteAnalyticsRule(string $ruleName): array
    {
        return $this->client->getAnalytics()->rules()[$ruleName]->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Search Presets
    |--------------------------------------------------------------------------
    */

    /**
     * Create or update a search preset.
     *
     * @param string $presetName
     * @param array  $presetsData e.g. ['value' => ['query_by' => 'name', 'sort_by' => '_text_match:desc']].
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function upsertPreset(string $presetName, array $presetsData): array
    {
        return $this->client->getPresets()->upsert($presetName, $presetsData);
    }

    /**
     * Retrieve all search presets.
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrievePresets(): array
    {
        return $this->client->getPresets()->retrieve();
    }

    /**
     * Retrieve a single search preset.
     *
     * @param string $presetName
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrievePreset(string $presetName): array
    {
        return $this->client->getPresets()[$presetName]->retrieve();
    }

    /**
     * Delete a search preset.
     *
     * @param string $presetName
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function deletePreset(string $presetName): array
    {
        return $this->client->getPresets()[$presetName]->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Stopwords
    |--------------------------------------------------------------------------
    */

    /**
     * Create or update a stopwords set.
     *
     * @param string $name
     * @param array  $config e.g. ['stopwords' => ['a', 'the'], 'locale' => 'en'].
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function upsertStopword(string $name, array $config): array
    {
        return $this->client->getStopwords()->put(array_merge($config, ['name' => $name]));
    }

    /**
     * Retrieve all stopwords sets.
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveStopwords(): array
    {
        return $this->client->getStopwords()->getAll();
    }

    /**
     * Retrieve a single stopwords set.
     *
     * @param string $name
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveStopword(string $name): array
    {
        return $this->client->getStopwords()->get($name);
    }

    /**
     * Delete a stopwords set.
     *
     * @param string $name
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function deleteStopword(string $name): array
    {
        return $this->client->getStopwords()->delete($name);
    }

    /*
    |--------------------------------------------------------------------------
    | Stemming Dictionaries
    |--------------------------------------------------------------------------
    */

    /**
     * Create or update a stemming dictionary.
     *
     * @param string $id
     * @param array  $wordRootCombinations e.g. [['word' => 'people', 'root' => 'person']].
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function upsertStemmingDictionary(string $id, array $wordRootCombinations): array
    {
        return $this->client->getStemming()->dictionaries()->upsert($id, $wordRootCombinations);
    }

    /**
     * Retrieve all stemming dictionaries.
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveStemmingDictionaries(): array
    {
        return $this->client->getStemming()->dictionaries()->retrieve();
    }

    /**
     * Retrieve a single stemming dictionary.
     *
     * @param string $id
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveStemmingDictionary(string $id): array
    {
        return $this->client->getStemming()->dictionaries()[$id]->retrieve();
    }

    /*
    |--------------------------------------------------------------------------
    | Conversation Models (RAG)
    |--------------------------------------------------------------------------
    */

    /**
     * Create a conversation model (used for conversational / RAG search).
     *
     * @param array $params e.g. ['model_name' => 'openai/gpt-3.5-turbo', 'api_key' => '...', 'history_collection' => '...'].
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function createConversationModel(array $params): array
    {
        return $this->client->getConversations()->getModels()->create($params);
    }

    /**
     * Retrieve all conversation models.
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveConversationModels(): array
    {
        return $this->client->getConversations()->getModels()->retrieve();
    }

    /**
     * Retrieve a single conversation model.
     *
     * @param string $id
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveConversationModel(string $id): array
    {
        return $this->client->getConversations()->getModels()[$id]->retrieve();
    }

    /**
     * Update a conversation model.
     *
     * @param string $id
     * @param array  $params
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function updateConversationModel(string $id, array $params): array
    {
        return $this->client->getConversations()->getModels()[$id]->update($params);
    }

    /**
     * Delete a conversation model.
     *
     * @param string $id
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function deleteConversationModel(string $id): array
    {
        return $this->client->getConversations()->getModels()[$id]->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Natural Language Search Models
    |--------------------------------------------------------------------------
    */

    /**
     * Create a natural language search model.
     *
     * @param array $params
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function createNLSearchModel(array $params): array
    {
        return $this->client->getNLSearchModels()->create($params);
    }

    /**
     * Retrieve all natural language search models.
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveNLSearchModels(): array
    {
        return $this->client->getNLSearchModels()->retrieve();
    }

    /**
     * Retrieve a single natural language search model.
     *
     * @param string $id
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function retrieveNLSearchModel(string $id): array
    {
        return $this->client->getNLSearchModels()[$id]->retrieve();
    }

    /**
     * Update a natural language search model.
     *
     * @param string $id
     * @param array  $params
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function updateNLSearchModel(string $id, array $params): array
    {
        return $this->client->getNLSearchModels()[$id]->update($params);
    }

    /**
     * Delete a natural language search model.
     *
     * @param string $id
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function deleteNLSearchModel(string $id): array
    {
        return $this->client->getNLSearchModels()[$id]->delete();
    }
}
