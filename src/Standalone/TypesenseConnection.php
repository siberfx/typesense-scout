<?php

namespace Siberfx\Typesense\Standalone;

use Typesense\Aliases;
use Typesense\Analytics;
use Typesense\AnalyticsV1;
use Typesense\Client;
use Typesense\Conversations;
use Typesense\CurationSets;
use Typesense\Debug;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Health;
use Typesense\Keys;
use Typesense\Metrics;
use Typesense\NLSearchModels;
use Typesense\Operations;
use Typesense\Presets;
use Typesense\Stemming;
use Typesense\Stopwords;
use Typesense\SynonymSets;

/**
 * Ergonomic, Scout-independent wrapper over ONE Typesense client. Every method
 * is a thin delegation; use client() for the raw client when you need it.
 */
class TypesenseConnection
{
    /**
     * @param array $publicNode Where browsers reach this cluster with a scoped key:
     *                          ['host' => ..., 'port' => ..., 'protocol' => ..., 'path' => ...].
     *                          The manager fills it from the connection's `public`
     *                          settings, falling back to the first node.
     */
    public function __construct(
        private readonly Client $client,
        private readonly array $publicNode = [],
    ) {
    }

    /** Raw client escape hatch. */
    public function client(): Client
    {
        return $this->client;
    }

    // ---- Collections ---------------------------------------------------

    /**
     * @param array $options Query parameters, e.g. ['src_name' => 'books'] to
     *                       clone another collection's schema.
     */
    public function createCollection(array $schema, array $options = []): array
    {
        return $this->client->getCollections()->create($schema, $options);
    }

    /**
     * Clone a collection's schema (server v30+: optionally with its documents).
     */
    public function cloneCollection(string $source, string $name, bool $copyDocuments = false): array
    {
        $options = ['src_name' => $source];

        if ($copyDocuments) {
            $options['copy_documents'] = true;
        }

        return $this->createCollection(['name' => $name], $options);
    }

    public function retrieveCollection(string $name): array
    {
        return $this->client->getCollections()[$name]->retrieve();
    }

    public function hasCollection(string $name): bool
    {
        try {
            $this->client->getCollections()[$name]->retrieve();

            return true;
        } catch (ObjectNotFound) {
            return false;
        }
    }

    /** Retrieve the collection, creating it from $schema when missing. */
    public function ensureCollection(array $schema): array
    {
        try {
            return $this->client->getCollections()[$schema['name']]->retrieve();
        } catch (ObjectNotFound) {
            return $this->client->getCollections()->create($schema);
        }
    }

    public function alterCollection(string $name, array $changes): array
    {
        return $this->client->getCollections()[$name]->update($changes);
    }

    public function dropCollection(string $name): array
    {
        return $this->client->getCollections()[$name]->delete();
    }

    public function listCollections(): array
    {
        return $this->client->getCollections()->retrieve();
    }

    /**
     * Delete every document in the collection but keep its schema (server v28+).
     *
     * @return int The number of deleted documents.
     */
    public function truncateCollection(string $name): int
    {
        $result = $this->client->getCollections()[$name]->getDocuments()->delete(['truncate' => 'true']);

        return (int) ($result['num_deleted'] ?? 0);
    }

    // ---- Documents -----------------------------------------------------

    public function documents(string $collection): DocumentActions
    {
        return new DocumentActions($this->client, $collection);
    }

    // ---- Search --------------------------------------------------------

    public function search(string $collection, array $params): array
    {
        return $this->client->getCollections()[$collection]->getDocuments()->search($params);
    }

    /**
     * Federated multi-search.
     *
     * @param array $searches List of search objects (each with `collection`, `q`, ...).
     * @param array $common   Query parameters common to all searches.
     * @param array $body     Extra request body options, e.g. ['union' => true,
     *                        'remove_duplicates' => false] (server v30+).
     */
    public function multiSearch(array $searches, array $common = [], array $body = []): array
    {
        return $this->client->getMultiSearch()->perform(array_merge($body, ['searches' => $searches]), $common);
    }

    /**
     * Union search (server v30+): merge the hits of every search into one
     * ordered result set.
     *
     * @param array $options Extra body options, e.g. ['remove_duplicates' => false].
     */
    public function unionSearch(array $searches, array $common = [], array $options = []): array
    {
        return $this->multiSearch($searches, $common, array_merge(['union' => true], $options));
    }

    // ---- Admin passthroughs (native resources) -------------------------

    public function keys(): Keys
    {
        return $this->client->getKeys();
    }

    public function generateScopedSearchKey(string $searchKey, array $parameters): string
    {
        return $this->client->getKeys()->generateScopedSearchKey($searchKey, $parameters);
    }

    /**
     * Browser credentials for searching straight from the frontend: a scoped key
     * plus the public host to send it to, as one array ready to hand to a SPA.
     *
     * @param string   $searchKey  Parent key; it must allow only `documents:search`.
     * @param array    $parameters Embedded search parameters, e.g. ['filter_by' => 'tenant:7'].
     * @param int|null $ttlSeconds Seconds until the key expires. Null leaves
     *                             `expires_at` as given in $parameters (if at all).
     * @return array{key: string, host: string|null, port: int|null, protocol: string|null, path: string, expires_at: int|null}
     */
    public function browserCredentials(string $searchKey, array $parameters = [], ?int $ttlSeconds = 3600): array
    {
        if ($ttlSeconds !== null) {
            $parameters['expires_at'] = time() + $ttlSeconds;
        }

        $port = $this->publicNode['port'] ?? null;

        return [
            'key' => $this->generateScopedSearchKey($searchKey, $parameters),
            'host' => $this->publicNode['host'] ?? null,
            'port' => $port === null || $port === '' ? null : (int) $port,
            'protocol' => $this->publicNode['protocol'] ?? null,
            'path' => (string) ($this->publicNode['path'] ?? ''),
            'expires_at' => isset($parameters['expires_at']) ? (int) $parameters['expires_at'] : null,
        ];
    }

    public function aliases(): Aliases
    {
        return $this->client->getAliases();
    }

    /** The collection an alias points to, or null when the alias does not exist. */
    public function aliasTarget(string $alias): ?string
    {
        try {
            return $this->client->getAliases()[$alias]->retrieve()['collection_name'] ?? null;
        } catch (ObjectNotFound) {
            return null;
        }
    }

    /**
     * Point an alias at another collection, e.g. after reindexing into a fresh
     * collection, so searches switch over without downtime.
     *
     * @param bool $dropPrevious Delete the collection the alias pointed to before.
     * @return string|null The collection the alias pointed to before, or null
     *                     when the alias is new.
     */
    public function swapAlias(string $alias, string $collection, bool $dropPrevious = false): ?string
    {
        $previous = $this->aliasTarget($alias);

        $this->client->getAliases()->upsert($alias, ['collection_name' => $collection]);

        if ($dropPrevious && $previous !== null && $previous !== $collection) {
            $this->dropCollection($previous);
        }

        return $previous;
    }

    public function presets(): Presets
    {
        return $this->client->getPresets();
    }

    public function stopwords(): Stopwords
    {
        return $this->client->getStopwords();
    }

    public function stemming(): Stemming
    {
        return $this->client->getStemming();
    }

    public function conversations(): Conversations
    {
        return $this->client->getConversations();
    }

    public function nlSearchModels(): NLSearchModels
    {
        return $this->client->getNLSearchModels();
    }

    public function analytics(): Analytics
    {
        return $this->client->getAnalytics();
    }

    /**
     * Global synonym sets (Typesense v6 / server 30+). Shareable across
     * collections via the collection's `synonym_sets` field.
     */
    public function synonymSets(): SynonymSets
    {
        return $this->client->getSynonymSets();
    }

    /**
     * Global curation sets (Typesense v6 / server 30+). Shareable across
     * collections via the collection's `curation_sets` field.
     */
    public function curationSets(): CurationSets
    {
        return $this->client->getCurationSets();
    }

    /**
     * Legacy (v1) analytics surface, alongside the reworked analytics() in v6.
     *
     * Note: the installed typesense-php v6.0.0 client exposes this only as a
     * public property (no `getAnalyticsV1()` getter, unlike its siblings).
     */
    public function analyticsV1(): AnalyticsV1
    {
        return $this->client->analyticsV1;
    }

    // ---- Cluster ops ---------------------------------------------------

    public function health(): Health
    {
        return $this->client->getHealth();
    }

    public function metrics(): Metrics
    {
        return $this->client->getMetrics();
    }

    public function debug(): Debug
    {
        return $this->client->getDebug();
    }

    public function operations(): Operations
    {
        return $this->client->getOperations();
    }
}
