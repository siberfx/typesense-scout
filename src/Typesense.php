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
}
