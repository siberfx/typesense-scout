<?php

namespace Siberfx\Typesense\Standalone;

use Typesense\Aliases;
use Typesense\Analytics;
use Typesense\Client;
use Typesense\Conversations;
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

/**
 * Ergonomic, Scout-independent wrapper over ONE Typesense client. Every method
 * is a thin delegation; use client() for the raw client when you need it.
 */
class TypesenseConnection
{
    public function __construct(private readonly Client $client)
    {
    }

    /** Raw client escape hatch. */
    public function client(): Client
    {
        return $this->client;
    }

    // ---- Collections ---------------------------------------------------

    public function createCollection(array $schema): array
    {
        return $this->client->getCollections()->create($schema);
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
     */
    public function multiSearch(array $searches, array $common = []): array
    {
        return $this->client->getMultiSearch()->perform(['searches' => $searches], $common);
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

    public function aliases(): Aliases
    {
        return $this->client->getAliases();
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
