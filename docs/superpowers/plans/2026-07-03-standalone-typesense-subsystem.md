# Standalone Typesense Subsystem — Implementation Plan (Phase 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Scout-independent way to talk to Typesense directly — a multi-connection manager, an ergonomic per-connection API, and a `TypesenseDirect` facade — without touching the existing Scout engine.

**Architecture:** A `TypesenseConnectionFactory` turns a settings array into a `\Typesense\Client`; a `TypesenseManager` holds named connections (lazy + cached), applying a Scout-config fallback only to the `default` connection; each `TypesenseConnection` wraps one client and exposes collections/documents/search/multiSearch/admin passthroughs plus a `client()` escape hatch. Registration folds into the existing `TypesenseServiceProvider`.

**Tech Stack:** PHP 8.4, `typesense/typesense-php` `^5.0` (installed v5.2.0), `illuminate/support`, PHPUnit `^11.5|^12.0`.

## Global Constraints

- Namespace for all new classes: `Siberfx\Typesense\Standalone`.
- Dependency stays `typesense/typesense-php: ^5.0` in Phase 1 — use only accessors present in v5.2.0. The v6-only `synonymSets`/`curationSets`/`analyticsV1` are **out of scope** (Phase 2).
- Do **not** modify the Scout engine, `src/Engines/TypesenseEngine.php`, `src/Typesense.php`, or `src/Mixin/BuilderMixin.php`.
- Tests run under plain `PHPUnit\Framework\TestCase` with **no booted Laravel container** — unit tests must construct objects directly with injected config arrays (mirror `tests/Unit/ScoutConfigTest.php`). Integration tests extend `Siberfx\Typesense\Tests\Integration\IntegrationTestCase` and skip cleanly when no server is reachable.
- Facade alias name: `TypesenseDirect`. Container binding key: `typesense.manager`.
- The `default` connection (and only it) merges `config('scout.typesense.client-settings')` as fallback; named connections must be fully specified.
- Exceptions bubble as `\Typesense\Exceptions\*`; only `ensureCollection`/`hasCollection` catch `ObjectNotFound` internally.
- Commit after every task with the repo's gitmoji-style messages.

## File Structure

| File | Responsibility |
|---|---|
| `config/typesense.php` (create) | Named connection definitions; `default` connection. |
| `src/Standalone/TypesenseConnectionFactory.php` (create) | `resolveSettings(connection, fallback)` + `make(settings): Client`. |
| `src/Standalone/DocumentActions.php` (create) | Per-collection document ops bound to one collection name. |
| `src/Standalone/TypesenseConnection.php` (create) | Ergonomic wrapper over ONE `\Typesense\Client`. |
| `src/Standalone/TypesenseManager.php` (create) | `connection(?name)`, caching, scout fallback, `__call` proxy. |
| `src/Standalone/TypesenseStandaloneFacade.php` (create) | Facade over `typesense.manager`. |
| `src/TypesenseServiceProvider.php` (modify) | Register `typesense.manager`, publish + merge `config/typesense.php`. |
| `composer.json` (modify) | Add `TypesenseDirect` alias under `extra.laravel.aliases`. |
| `tests/Unit/Standalone/*` (create) | Container-free unit tests. |
| `tests/Integration/StandaloneIntegrationTest.php` (create) | Live-server end-to-end. |
| `README.md`, `CHANGELOG.md` (modify) | Document the standalone subsystem. |

---

### Task 1: Config file + shape guard

**Files:**
- Create: `config/typesense.php`
- Test: `tests/Unit/Standalone/StandaloneConfigTest.php`

**Interfaces:**
- Produces: a config array of shape `['default' => string, 'connections' => ['default' => array, ...]]` where each connection may contain `api_key`, `nodes`, `nearest_node`, `connection_timeout_seconds`, `healthcheck_interval_seconds`, `num_retries`, `retry_interval_seconds`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;

class StandaloneConfigTest extends TestCase
{
    private function config(): array
    {
        return require __DIR__ . '/../../../config/typesense.php';
    }

    public function test_has_default_connection_name(): void
    {
        $config = $this->config();

        $this->assertArrayHasKey('default', $config);
        $this->assertArrayHasKey('connections', $config);
    }

    public function test_default_connection_defines_expected_keys(): void
    {
        $connections = $this->config()['connections'];

        $this->assertArrayHasKey('default', $connections);
        $this->assertArrayHasKey('api_key', $connections['default']);
        $this->assertArrayHasKey('nodes', $connections['default']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Standalone/StandaloneConfigTest.php`
Expected: FAIL — `failed to open stream`/file not found for `config/typesense.php`.

- [ ] **Step 3: Create the config file**

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Standalone Connection
    |--------------------------------------------------------------------------
    |
    | The connection used when you call the standalone client without naming
    | one. Standalone Typesense access is independent of Laravel Scout.
    |
    */

    'default' => env('TYPESENSE_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Each connection is passed to the Typesense PHP client. Any value left
    | null (and an empty `nodes`/`nearest_node`) on the `default` connection
    | falls back to `scout.typesense.client-settings`, so existing apps need
    | no new configuration. Named connections must be fully specified.
    |
    */

    'connections' => [

        'default' => [
            'api_key' => env('TYPESENSE_API_KEY'),
            'nodes' => array_values(array_filter([
                [
                    'host'     => env('TYPESENSE_HOST'),
                    'port'     => env('TYPESENSE_PORT'),
                    'path'     => env('TYPESENSE_PATH', ''),
                    'protocol' => env('TYPESENSE_PROTOCOL'),
                ],
            ], static fn (array $node): bool => ! empty($node['host']))),
            'nearest_node' => null,
            'connection_timeout_seconds'   => env('TYPESENSE_CONNECTION_TIMEOUT_SECONDS', 2),
            'healthcheck_interval_seconds' => env('TYPESENSE_HEALTHCHECK_INTERVAL_SECONDS', 15),
            'num_retries'                  => env('TYPESENSE_NUM_RETRIES', 3),
            'retry_interval_seconds'       => env('TYPESENSE_RETRY_INTERVAL_SECONDS', 1),
        ],

    ],
];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Standalone/StandaloneConfigTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add config/typesense.php tests/Unit/Standalone/StandaloneConfigTest.php
git commit -m ":sparkles: Add standalone Typesense connections config"
```

---

### Task 2: TypesenseConnectionFactory

**Files:**
- Create: `src/Standalone/TypesenseConnectionFactory.php`
- Test: `tests/Unit/Standalone/TypesenseConnectionFactoryTest.php`

**Interfaces:**
- Produces:
  - `resolveSettings(array $connection, array $fallback = []): array` — merges `$connection` over `$fallback`; a connection value is skipped (fallback wins) when it is `null`, or when the key is `nodes`/`nearest_node` and the value is empty.
  - `make(array $settings): \Typesense\Client` — constructs the client (no network call at construction).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Standalone\TypesenseConnectionFactory;
use Typesense\Client;

class TypesenseConnectionFactoryTest extends TestCase
{
    private TypesenseConnectionFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new TypesenseConnectionFactory();
    }

    public function test_connection_value_overrides_fallback(): void
    {
        $settings = $this->factory->resolveSettings(
            ['api_key' => 'conn-key'],
            ['api_key' => 'scout-key', 'num_retries' => 3]
        );

        $this->assertSame('conn-key', $settings['api_key']);
        $this->assertSame(3, $settings['num_retries']); // fallback preserved
    }

    public function test_null_connection_value_falls_back(): void
    {
        $settings = $this->factory->resolveSettings(
            ['api_key' => null],
            ['api_key' => 'scout-key']
        );

        $this->assertSame('scout-key', $settings['api_key']);
    }

    public function test_empty_nodes_falls_back(): void
    {
        $fallbackNodes = [['host' => 'scout-host', 'port' => '8108', 'protocol' => 'http']];

        $settings = $this->factory->resolveSettings(
            ['nodes' => []],
            ['nodes' => $fallbackNodes]
        );

        $this->assertSame($fallbackNodes, $settings['nodes']);
    }

    public function test_make_returns_a_client(): void
    {
        $client = $this->factory->make([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);

        $this->assertInstanceOf(Client::class, $client);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Standalone/TypesenseConnectionFactoryTest.php`
Expected: FAIL — class `TypesenseConnectionFactory` not found.

- [ ] **Step 3: Write the factory**

```php
<?php

namespace Siberfx\Typesense\Standalone;

use Typesense\Client;

/**
 * Builds a raw Typesense client from a connection settings array, applying an
 * optional fallback (e.g. the Scout client settings) for the default connection.
 */
class TypesenseConnectionFactory
{
    /**
     * Merge a connection's settings over a fallback array. A connection value
     * is used when present and meaningful; otherwise the fallback value applies.
     *
     * @param array $connection The connection-specific settings.
     * @param array $fallback   Values to inherit when the connection omits them.
     */
    public function resolveSettings(array $connection, array $fallback = []): array
    {
        $settings = $fallback;

        foreach ($connection as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (($key === 'nodes' || $key === 'nearest_node') && empty($value)) {
                continue;
            }

            $settings[$key] = $value;
        }

        return $settings;
    }

    /**
     * Construct a Typesense client. Construction does not open a connection;
     * the first request triggers HTTP client discovery (php-http/discovery).
     */
    public function make(array $settings): Client
    {
        return new Client($settings);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Standalone/TypesenseConnectionFactoryTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Standalone/TypesenseConnectionFactory.php tests/Unit/Standalone/TypesenseConnectionFactoryTest.php
git commit -m ":sparkles: Add TypesenseConnectionFactory with scout fallback merge"
```

---

### Task 3: DocumentActions

**Files:**
- Create: `src/Standalone/DocumentActions.php`
- Test: `tests/Unit/Standalone/DocumentActionsTest.php`

**Interfaces:**
- Consumes: `\Typesense\Client` (uses `getCollections()[$name]->getDocuments()`).
- Produces (all delegate to `\Typesense\Documents`):
  - `collection(): string`
  - `create(array $document, array $options = []): array`
  - `upsert(array $document, array $options = []): array`
  - `update(array $document, array $options = []): array`
  - `retrieve(string $id): array`
  - `delete(string $id): array`
  - `deleteByFilter(array $query): array`
  - `import(iterable $documents, string $action = 'upsert'): array` (accepts array or JSONL string; passes through `['action' => $action]`)
  - `export(array $params = []): string`

- [ ] **Step 1: Write the failing test**

Construction is network-free, so unit-test the pure parts (binding + collection name).

```php
<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Standalone\DocumentActions;
use Typesense\Client;

class DocumentActionsTest extends TestCase
{
    private function client(): Client
    {
        return new Client([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);
    }

    public function test_exposes_bound_collection_name(): void
    {
        $actions = new DocumentActions($this->client(), 'books');

        $this->assertSame('books', $actions->collection());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Standalone/DocumentActionsTest.php`
Expected: FAIL — class `DocumentActions` not found.

- [ ] **Step 3: Write DocumentActions**

```php
<?php

namespace Siberfx\Typesense\Standalone;

use Typesense\Client;
use Typesense\Documents;

/**
 * Document operations bound to a single collection. Returned by
 * TypesenseConnection::documents(). Thin delegations to \Typesense\Documents.
 */
class DocumentActions
{
    public function __construct(
        private readonly Client $client,
        private readonly string $collection,
    ) {
    }

    public function collection(): string
    {
        return $this->collection;
    }

    public function create(array $document, array $options = []): array
    {
        return $this->documents()->create($document, $options);
    }

    public function upsert(array $document, array $options = []): array
    {
        return $this->documents()->upsert($document, $options);
    }

    public function update(array $document, array $options = []): array
    {
        return $this->documents()->update($document, $options);
    }

    public function retrieve(string $id): array
    {
        return $this->documents()[$id]->retrieve();
    }

    public function delete(string $id): array
    {
        return $this->documents()[$id]->delete();
    }

    /**
     * Delete every document matching a filter, e.g. ['filter_by' => 'year:<1950'].
     */
    public function deleteByFilter(array $query): array
    {
        return $this->documents()->delete($query);
    }

    /**
     * Bulk import. Accepts an array of associative docs or a JSONL string.
     *
     * @param iterable|string $documents
     * @param string          $action    create|upsert|update|emplace
     * @return array The per-line import results.
     */
    public function import($documents, string $action = 'upsert'): array
    {
        return $this->documents()->import($documents, ['action' => $action]);
    }

    public function export(array $params = []): string
    {
        return $this->documents()->export($params);
    }

    private function documents(): Documents
    {
        return $this->client->getCollections()[$this->collection]->getDocuments();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Standalone/DocumentActionsTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add src/Standalone/DocumentActions.php tests/Unit/Standalone/DocumentActionsTest.php
git commit -m ":sparkles: Add DocumentActions for per-collection document ops"
```

---

### Task 4: TypesenseConnection

**Files:**
- Create: `src/Standalone/TypesenseConnection.php`
- Test: `tests/Unit/Standalone/TypesenseConnectionTest.php`

**Interfaces:**
- Consumes: `\Typesense\Client`, `DocumentActions` (Task 3).
- Produces:
  - `client(): \Typesense\Client`
  - `createCollection(array $schema): array`
  - `retrieveCollection(string $name): array`
  - `hasCollection(string $name): bool` (catches `ObjectNotFound`)
  - `ensureCollection(array $schema): array` (retrieve-or-create; `$schema['name']` is the key)
  - `alterCollection(string $name, array $changes): array`
  - `dropCollection(string $name): array`
  - `listCollections(): array`
  - `documents(string $collection): DocumentActions`
  - `search(string $collection, array $params): array`
  - `multiSearch(array $searches, array $common = []): array` (wraps as `['searches' => $searches]`)
  - Admin passthroughs returning native resources: `keys()`, `aliases()`, `presets()`, `stopwords()`, `stemming()`, `conversations()`, `nlSearchModels()`, `analytics()`
  - `generateScopedSearchKey(string $searchKey, array $parameters): string`
  - Ops: `health()`, `metrics()`, `debug()`, `operations()`

- [ ] **Step 1: Write the failing test**

Client-interaction methods are covered by the integration task; here unit-test the network-free wiring: `client()` returns the injected client and `documents()` returns a bound `DocumentActions`.

```php
<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Standalone\DocumentActions;
use Siberfx\Typesense\Standalone\TypesenseConnection;
use Typesense\Client;

class TypesenseConnectionTest extends TestCase
{
    private function connection(): TypesenseConnection
    {
        $client = new Client([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);

        return new TypesenseConnection($client);
    }

    public function test_client_returns_injected_client(): void
    {
        $client = new Client([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);
        $connection = new TypesenseConnection($client);

        $this->assertSame($client, $connection->client());
    }

    public function test_documents_returns_bound_document_actions(): void
    {
        $actions = $this->connection()->documents('books');

        $this->assertInstanceOf(DocumentActions::class, $actions);
        $this->assertSame('books', $actions->collection());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Standalone/TypesenseConnectionTest.php`
Expected: FAIL — class `TypesenseConnection` not found.

- [ ] **Step 3: Write TypesenseConnection**

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Standalone/TypesenseConnectionTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Standalone/TypesenseConnection.php tests/Unit/Standalone/TypesenseConnectionTest.php
git commit -m ":sparkles: Add TypesenseConnection ergonomic client wrapper"
```

---

### Task 5: TypesenseManager

**Files:**
- Create: `src/Standalone/TypesenseManager.php`
- Test: `tests/Unit/Standalone/TypesenseManagerTest.php`

**Interfaces:**
- Consumes: `TypesenseConnectionFactory` (Task 2), `TypesenseConnection` (Task 4).
- Produces:
  - `__construct(array $config, array $scoutFallback = [], ?TypesenseConnectionFactory $factory = null)` — `$config` is the `config('typesense')` array (`['default' => string, 'connections' => [...]]`).
  - `static fromConfig(array $config, array $scoutFallback = [], ?TypesenseConnectionFactory $factory = null): self`
  - `resolveSettingsFor(?string $name = null): array` — merged settings; scout fallback applied only when the resolved name equals the config `default`.
  - `connection(?string $name = null): TypesenseConnection` — lazily built and cached per name.
  - `__call(string $method, array $parameters)` — proxies to the default connection.
  - Throws `\InvalidArgumentException` for an unknown connection name.

- [ ] **Step 1: Write the failing test**

`client()` is network-free, so it drives both the caching and the `__call`-proxy assertions.

```php
<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Standalone\TypesenseConnection;
use Siberfx\Typesense\Standalone\TypesenseManager;

class TypesenseManagerTest extends TestCase
{
    private function config(): array
    {
        return [
            'default' => 'default',
            'connections' => [
                'default' => ['api_key' => null, 'nodes' => []],
                'analytics' => [
                    'api_key' => 'analytics-key',
                    'nodes' => [['host' => 'a-host', 'port' => '8108', 'protocol' => 'http']],
                ],
            ],
        ];
    }

    private function scoutFallback(): array
    {
        return [
            'api_key' => 'scout-key',
            'nodes' => [['host' => 'scout-host', 'port' => '8108', 'protocol' => 'http']],
        ];
    }

    public function test_default_connection_merges_scout_fallback(): void
    {
        $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

        $settings = $manager->resolveSettingsFor('default');

        $this->assertSame('scout-key', $settings['api_key']);
        $this->assertSame('scout-host', $settings['nodes'][0]['host']);
    }

    public function test_named_connection_does_not_get_scout_fallback(): void
    {
        $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

        $settings = $manager->resolveSettingsFor('analytics');

        $this->assertSame('analytics-key', $settings['api_key']);
        $this->assertSame('a-host', $settings['nodes'][0]['host']);
    }

    public function test_unknown_connection_throws(): void
    {
        $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

        $this->expectException(InvalidArgumentException::class);
        $manager->connection('does-not-exist');
    }

    public function test_connection_is_cached_and_default_resolves(): void
    {
        $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

        $this->assertInstanceOf(TypesenseConnection::class, $manager->connection());
        $this->assertSame($manager->connection(), $manager->connection('default'));
    }

    public function test_call_proxies_to_default_connection(): void
    {
        $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

        // client() is network-free; proves __call routes to the default connection.
        $this->assertSame(
            $manager->connection('default')->client(),
            $manager->client()
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Standalone/TypesenseManagerTest.php`
Expected: FAIL — class `TypesenseManager` not found.

- [ ] **Step 3: Write TypesenseManager**

```php
<?php

namespace Siberfx\Typesense\Standalone;

use InvalidArgumentException;

/**
 * Holds named standalone Typesense connections. The default connection inherits
 * the Scout client settings as a fallback; named connections stand alone.
 */
class TypesenseManager
{
    private string $defaultConnection;

    /** @var array<string, array> */
    private array $connectionsConfig;

    /** @var array<string, TypesenseConnection> */
    private array $resolved = [];

    private TypesenseConnectionFactory $factory;

    /**
     * @param array $config        The `config('typesense')` array.
     * @param array $scoutFallback The `config('scout.typesense.client-settings')` array.
     */
    public function __construct(
        array $config,
        private readonly array $scoutFallback = [],
        ?TypesenseConnectionFactory $factory = null,
    ) {
        $this->defaultConnection = $config['default'] ?? 'default';
        $this->connectionsConfig = $config['connections'] ?? [];
        $this->factory = $factory ?? new TypesenseConnectionFactory();
    }

    public static function fromConfig(
        array $config,
        array $scoutFallback = [],
        ?TypesenseConnectionFactory $factory = null,
    ): self {
        return new self($config, $scoutFallback, $factory);
    }

    /**
     * Resolve the final client settings for a connection. The default
     * connection (and only it) inherits the Scout client settings.
     */
    public function resolveSettingsFor(?string $name = null): array
    {
        $name ??= $this->defaultConnection;

        if (! array_key_exists($name, $this->connectionsConfig)) {
            throw new InvalidArgumentException("Typesense connection [{$name}] is not configured.");
        }

        $fallback = $name === $this->defaultConnection ? $this->scoutFallback : [];

        return $this->factory->resolveSettings($this->connectionsConfig[$name], $fallback);
    }

    public function connection(?string $name = null): TypesenseConnection
    {
        $name ??= $this->defaultConnection;

        return $this->resolved[$name] ??= new TypesenseConnection(
            $this->factory->make($this->resolveSettingsFor($name))
        );
    }

    /**
     * Proxy calls to the default connection so the facade reads fluently, e.g.
     * TypesenseDirect::search(...).
     */
    public function __call(string $method, array $parameters)
    {
        return $this->connection()->{$method}(...$parameters);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Standalone/TypesenseManagerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Standalone/TypesenseManager.php tests/Unit/Standalone/TypesenseManagerTest.php
git commit -m ":sparkles: Add TypesenseManager with named connections & scout fallback"
```

---

### Task 6: Facade + service-provider wiring + composer alias

**Files:**
- Create: `src/Standalone/TypesenseStandaloneFacade.php`
- Modify: `src/TypesenseServiceProvider.php`
- Modify: `composer.json` (add `TypesenseDirect` to `extra.laravel.aliases`)
- Test: `tests/Unit/Standalone/TypesenseStandaloneFacadeTest.php`

**Interfaces:**
- Consumes: `TypesenseManager` (Task 5).
- Produces: facade with accessor string `typesense.manager`; container binding `typesense.manager` → singleton `TypesenseManager`; published config `config/typesense.php`.

- [ ] **Step 1: Write the failing test**

The facade accessor is a protected static; assert it via reflection (no container needed).

```php
<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Siberfx\Typesense\Standalone\TypesenseStandaloneFacade;

class TypesenseStandaloneFacadeTest extends TestCase
{
    public function test_facade_accessor_points_at_the_manager_binding(): void
    {
        $method = new ReflectionMethod(TypesenseStandaloneFacade::class, 'getFacadeAccessor');
        $method->setAccessible(true);

        $this->assertSame('typesense.manager', $method->invoke(null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Standalone/TypesenseStandaloneFacadeTest.php`
Expected: FAIL — class `TypesenseStandaloneFacade` not found.

- [ ] **Step 3: Write the facade**

```php
<?php

namespace Siberfx\Typesense\Standalone;

use Illuminate\Support\Facades\Facade;

/**
 * @method static TypesenseConnection connection(?string $name = null)
 * @method static array  createCollection(array $schema)
 * @method static array  ensureCollection(array $schema)
 * @method static bool   hasCollection(string $name)
 * @method static array  dropCollection(string $name)
 * @method static array  listCollections()
 * @method static DocumentActions documents(string $collection)
 * @method static array  search(string $collection, array $params)
 * @method static array  multiSearch(array $searches, array $common = [])
 * @method static string generateScopedSearchKey(string $searchKey, array $parameters)
 *
 * @see \Siberfx\Typesense\Standalone\TypesenseManager
 */
class TypesenseStandaloneFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'typesense.manager';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Standalone/TypesenseStandaloneFacadeTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Wire the service provider**

Modify `src/TypesenseServiceProvider.php`. Add the import near the other `use` lines:

```php
use Siberfx\Typesense\Standalone\TypesenseManager;
```

In `register()`, after the existing `Typesense::class` singleton and alias, add:

```php
        $this->mergeConfigFrom(__DIR__ . '/../config/typesense.php', 'typesense');

        $this->app->singleton('typesense.manager', static function ($app) {
            return TypesenseManager::fromConfig(
                $app['config']->get('typesense', []),
                $app['config']->get('scout.typesense.client-settings', []),
            );
        });

        $this->app->alias('typesense.manager', TypesenseManager::class);
```

In `boot()`, after `$this->registerMacros();`, add the publish tag:

```php
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/typesense.php' => $this->app->configPath('typesense.php'),
            ], 'typesense-config');
        }
```

- [ ] **Step 6: Add the facade alias to composer.json**

In `composer.json`, under `extra.laravel.aliases`, add the `TypesenseDirect` entry beside the existing `Typesense` alias:

```json
            "aliases": {
                "Typesense": "Siberfx\\Typesense\\TypesenseFacade",
                "TypesenseDirect": "Siberfx\\Typesense\\Standalone\\TypesenseStandaloneFacade"
            }
```

- [ ] **Step 7: Run the full unit suite**

Run: `vendor/bin/phpunit --testsuite Unit` (or `vendor/bin/phpunit tests/Unit`)
Expected: PASS — all existing unit tests plus the new `Standalone` tests are green.

- [ ] **Step 8: Commit**

```bash
git add src/Standalone/TypesenseStandaloneFacade.php src/TypesenseServiceProvider.php composer.json tests/Unit/Standalone/TypesenseStandaloneFacadeTest.php
git commit -m ":sparkles: Register standalone manager, facade & publishable config"
```

---

### Task 7: Integration test (live server, env-guarded)

**Files:**
- Create: `tests/Integration/StandaloneIntegrationTest.php`

**Interfaces:**
- Consumes: `IntegrationTestCase` (`$this->client` is a healthy `\Typesense\Client`), `TypesenseManager`, `TypesenseConnection`, `DocumentActions`.

- [ ] **Step 1: Write the integration test**

Build the manager from an explicit single-connection config wrapping the base test client's settings, then exercise the full lifecycle. Skips cleanly when no server is reachable (inherited from `IntegrationTestCase`).

```php
<?php

namespace Siberfx\Typesense\Tests\Integration;

use Siberfx\Typesense\Standalone\TypesenseConnection;
use Siberfx\Typesense\Standalone\TypesenseManager;

class StandaloneIntegrationTest extends IntegrationTestCase
{
    private function manager(): TypesenseManager
    {
        return TypesenseManager::fromConfig([
            'default' => 'default',
            'connections' => [
                'default' => [
                    'api_key' => getenv('TYPESENSE_API_KEY') ?: 'xyz',
                    'nodes' => [[
                        'host' => getenv('TYPESENSE_HOST') ?: 'localhost',
                        'port' => getenv('TYPESENSE_PORT') ?: '8108',
                        'protocol' => getenv('TYPESENSE_PROTOCOL') ?: 'http',
                    ]],
                    'connection_timeout_seconds' => 2,
                ],
            ],
        ]);
    }

    public function test_full_document_lifecycle(): void
    {
        $manager = $this->manager();
        $connection = $manager->connection();
        $this->assertInstanceOf(TypesenseConnection::class, $connection);

        $collection = 'standalone_books';
        $this->dropCollection($collection);

        // create (idempotent)
        $connection->ensureCollection([
            'name' => $collection,
            'fields' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'year', 'type' => 'int32'],
            ],
            'default_sorting_field' => 'year',
        ]);
        $this->assertTrue($connection->hasCollection($collection));

        // bulk import
        $results = $connection->documents($collection)->import([
            ['id' => '1', 'title' => 'Dune', 'year' => 1965],
            ['id' => '2', 'title' => 'Neuromancer', 'year' => 1984],
        ], 'upsert');
        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['success']);

        // single search
        $hits = $connection->search($collection, ['q' => 'dune', 'query_by' => 'title']);
        $this->assertSame(1, $hits['found']);

        // federated multi-search
        $multi = $connection->multiSearch([
            ['collection' => $collection, 'q' => 'dune', 'query_by' => 'title'],
            ['collection' => $collection, 'q' => 'neuromancer', 'query_by' => 'title'],
        ]);
        $this->assertCount(2, $multi['results']);

        // delete by filter + drop
        $connection->documents($collection)->deleteByFilter(['filter_by' => 'year:<1970']);
        $after = $connection->search($collection, ['q' => '*', 'query_by' => 'title']);
        $this->assertSame(1, $after['found']);

        $connection->dropCollection($collection);
        $this->assertFalse($connection->hasCollection($collection));
    }
}
```

- [ ] **Step 2: Run the integration test**

Run (no server): `vendor/bin/phpunit tests/Integration/StandaloneIntegrationTest.php`
Expected: SKIPPED — "Typesense server not available".

Run (with a local server, e.g. `docker run -p 8108:8108 typesense/typesense:30.2 --data-dir /data --api-key=xyz --enable-cors`):
Expected: PASS (1 test).

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/StandaloneIntegrationTest.php
git commit -m ":white_check_mark: Add standalone Typesense integration test"
```

---

### Task 8: Documentation

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:** none (docs only).

- [ ] **Step 1: Add a README section**

Append a "Standalone Typesense (without Scout)" section documenting:
- Publishing config: `php artisan vendor:publish --tag=typesense-config`.
- The `default` connection inherits `scout.typesense.client-settings`; named connections stand alone.
- Usage via the facade and the container:

````markdown
## Standalone Typesense (without Scout)

Talk to Typesense directly — no Scout model required — via the `TypesenseDirect`
facade or the `typesense.manager` container binding.

```php
use TypesenseDirect;

// Create a collection by hand
TypesenseDirect::ensureCollection([
    'name' => 'books',
    'fields' => [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'year',  'type' => 'int32'],
    ],
    'default_sorting_field' => 'year',
]);

// Bulk import
TypesenseDirect::documents('books')->import([
    ['id' => '1', 'title' => 'Dune', 'year' => 1965],
], 'upsert');

// Search & federated multi-search
$hits  = TypesenseDirect::search('books', ['q' => 'dune', 'query_by' => 'title']);
$multi = TypesenseDirect::multiSearch([
    ['collection' => 'books', 'q' => 'dune', 'query_by' => 'title'],
]);

// A second cluster (named connection defined in config/typesense.php)
TypesenseDirect::connection('analytics')->search('events', [...]);

// Raw client escape hatch
$client = TypesenseDirect::connection()->client();
```

Publish the config to define additional connections:

```bash
php artisan vendor:publish --tag=typesense-config
```
````

- [ ] **Step 2: Add a CHANGELOG entry**

Add under an "Unreleased" heading:

```markdown
## Unreleased

### Added
- Standalone Typesense subsystem (no Scout): `TypesenseManager`, `TypesenseConnection`,
  `DocumentActions`, `TypesenseConnectionFactory`, and the `TypesenseDirect` facade.
  Named multi-connection support via a new publishable `config/typesense.php`; the
  `default` connection inherits `scout.typesense.client-settings`.
```

- [ ] **Step 3: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m ":memo: Document standalone Typesense subsystem"
```

---

## Self-Review

**Spec coverage:**
- Multi-connection subsystem → Tasks 2–6. ✓
- `config/typesense.php` with `default` scout fallback → Tasks 1, 5. ✓
- `TypesenseConnection` surface (collections, documents, search, multiSearch, admin passthroughs, ops, `client()`) → Task 4. ✓ (v6-only trio deferred to Phase 2 per spec.)
- Fold registration into existing provider → Task 6. ✓
- `TypesenseDirect` facade alias → Task 6. ✓
- Scout fallback only for `default` → Task 5 (`resolveSettingsFor`). ✓
- Error handling: bubble, only `ensure/has` catch `ObjectNotFound` → Task 4. ✓
- Testing: container-free unit + env-guarded integration → Tasks 1–7. ✓

**Placeholder scan:** No TBD/TODO; every code step shows complete code. ✓

**Type consistency:** `resolveSettings` / `make` (Task 2) match usage in Task 5; `TypesenseConnection` methods (Task 4) match facade `@method` docblocks (Task 6) and integration usage (Task 7); `DocumentActions::import` returns `array` and is asserted with `count()` in Task 7; `multiSearch($searches, $common)` wraps `['searches' => $searches]` consistently in Task 4 and is called with a list in Task 7. ✓

## Out of scope (Phase 2, separate plan)
- Bump `typesense/typesense-php` to `^6.0`, target server 30.x.
- Patch `src/Typesense.php` for v6 breakages (analytics rework; per-collection synonyms/overrides).
- Add v6-only `synonymSets()`, `curationSets()`, `analyticsV1()` to `TypesenseConnection`.
- Refresh the `typesense-standalone` skill version table; README/CHANGELOG.
