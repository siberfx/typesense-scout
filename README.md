# Laravel Scout Typesense Driver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/siberfx/typesense-scout.svg?style=flat-square)](https://packagist.org/packages/siberfx/typesense-scout)
[![Total Downloads](https://img.shields.io/packagist/dt/siberfx/typesense-scout.svg?style=flat-square)](https://packagist.org/packages/siberfx/typesense-scout)
[![Tests](https://github.com/siberfx/typesense-scout/actions/workflows/pull-request.yml/badge.svg?event=push)](https://github.com/siberfx/typesense-scout/actions/workflows/pull-request.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/badge/php-8.4%20%7C%208.5-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/supported-versions.php)
[![Laravel](https://img.shields.io/badge/laravel-12.69%2B%20%7C%2013.30%2B-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com/docs/13.x/releases)
[![License](https://img.shields.io/packagist/l/siberfx/typesense-scout.svg?style=flat-square)](LICENSE)

This package makes it easy to add full text search support to your models with Laravel 12 and 13 on PHP 8.4 and 8.5.
On top of the Scout driver it ships Typesense-specific extras — vector / hybrid / semantic search, scoped search keys,
admin API wrappers — and a standalone `TypesenseDirect` client for talking to Typesense without Scout.

## Requirements

| Dependency                | Supported versions                     |
|---------------------------|----------------------------------------|
| PHP                       | 8.4, 8.5                               |
| Laravel (`illuminate/*`)  | 12.69+ or 13.30+                       |
| Laravel Scout             | 11.8+                                  |
| `typesense/typesense-php` | 6.x                                    |
| Typesense server          | 30.x                                   |

> [!NOTE]
> The Laravel floor is intentionally above the releases affected by published security advisories
> (most recently CVE-2026-102279, fixed in 12.69.0 / 13.30.0). Composer will refuse to install this package
> alongside an older, vulnerable Laravel release — run `composer update laravel/framework` first if it does.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Standalone Typesense (without Scout)](#standalone-typesense-without-scout)
- [Migrating from siberfx/laravel-typesense](#migrating-from-siberfxlaravel-typesense)
- [Testing](#testing)
- [Authors](#authors)
- [License](#license)


## Installation
The Typesense PHP SDK uses httplug to interface with various PHP HTTP libraries through a single API.

First, install the httplug adapter that matches your `guzzlehttp/guzzle` version. Laravel 12 and 13 ship
with Guzzle 7, so run:

```bash
composer require php-http/guzzle7-adapter
```

Then install the driver:

```bash
composer require siberfx/typesense-scout
```

And add the service provider:

```php
// config/app.php
'providers' => [
    // ...
    Siberfx\Typesense\TypesenseServiceProvider::class,
],
```

Ensure you have Laravel Scout as a provider too otherwise you will get an "unresolvable dependency" error

```php
// config/app.php
'providers' => [
    // ...
    Laravel\Scout\ScoutServiceProvider::class,
],
```

Add `SCOUT_DRIVER=typesense` to your `.env` file

Then you should publish `scout.php` configuration file to your config directory

```bash
php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"
```

In your `config/scout.php` add:

```php

'typesense' => [
    'api_key'         => 'abcd',
    'nodes'           => [
      [
        'host'     => 'localhost',
        'port'     => '8108',
        'path'     => '',
        'protocol' => 'http',
      ],
    ],
    'nearest_node'    => [
        'host'     => 'localhost',
        'port'     => '8108',
        'path'     => '',
        'protocol' => 'http',
    ],
    'connection_timeout_seconds'   => 2,
    'healthcheck_interval_seconds' => 30,    
    'num_retries'                  => 3,
    'retry_interval_seconds'       => 1,
  ],
```

## Usage

If you are unfamiliar with Laravel Scout, we suggest reading it's [documentation](https://laravel.com/docs/12.x/scout) first.

After you have installed scout and the Typesense driver, you need to add the
`Searchable` trait to your models that you want to make searchable. Additionaly,
define the fields you want to make searchable by defining the `toSearchableArray` method on the model and implement `TypesenseSearch`:

```php
<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Siberfx\Typesense\Interfaces\TypesenseDocument;
use Laravel\Scout\Searchable;

class Todo extends Model implements TypesenseDocument
{
    use Searchable;
    
     /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        return array_merge(
            $this->toArray(), 
            [
                // Cast id to string and turn created_at into an int32 timestamp
                // in order to maintain compatibility with the Typesense index definition below
                'id' => (string) $this->id,
                'created_at' => $this->created_at->timestamp,
            ]
        );
    }

     /**
     * The Typesense schema to be created.
     *
     * @return array
     */
    public function getCollectionSchema(): array {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                [
                    'name' => 'id',
                    'type' => 'string',
                ],
                [
                    'name' => 'name',
                    'type' => 'string',
                ],
                [
                    'name' => 'created_at',
                    'type' => 'int64',
                ],
            ],
            'default_sorting_field' => 'created_at',
        ];
    }

     /**
     * The fields to be queried against. See https://typesense.org/docs/0.24.0/api/search.html.
     *
     * @return array
     */
    public function typesenseQueryBy(): array {
        return [
            'name',
        ];
    }    
}
```

Then, sync the data with the search service like:

`php artisan scout:import App\\Models\\Todo`

After that you can search your models with:

`Todo::search('Test')->get();`

## Adding via Query
The `searchable()` method will chunk the results of the query and add the records to your search index. Examples:

```php
$todo = Todo::find(1);
$todo->searchable();

$todos = Todo::where('created_at', '<', now())->get();
$todos->searchable();
```

### Multi Search
You can send multiple search requests in a single HTTP request, using the Multi-Search feature.
```php
$searchRequests = [
    [
      'collection' => 'todo',
      'q' => 'todo'
    ],
    [
      'collection' => 'todo',
      'q' => 'foo'
    ]
];

Todo::search('')->searchMulti($searchRequests)->paginateRaw();
```

### Generate Scoped Search Key

You can generate scoped search API keys that have embedded search parameters in them. This is useful in a few different scenarios:
1. You can index data from multiple users/customers in a single Typesense collection (aka multi-tenancy) and create scoped search keys with embedded `filter_by` parameters that only allow users access to their own subset of data.
2. You can embed any [search parameters](https://typesense.org/docs/0.24.0/api/search.html#search-parameters) (for eg: `exclude_fields` or `limit_hits`) to prevent users from being able to modify it client-side.

When you use these scoped search keys in a search API call, the parameters you embedded in them will be automatically applied by Typesense and users will not be able to override them.

```php
<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use Siberfx\Typesense\Concerns\HasScopedApiKey;
use Siberfx\Typesense\Interfaces\TypesenseDocument;

class Todo extends Model implements TypesenseDocument
{
    use Searchable, HasScopedApiKey;
}
```

#### Usage

Generate a scoped search key from a parent search-only API key, embedding the
parameters you want to enforce (e.g. a per-tenant `filter_by` and/or an
`expires_at`), then use it for searching:

```php
// Generate a scoped key that locks searches to a single tenant.
$scopedKey = Todo::generateScopedSearchKey('parent-search-only-key', [
    'filter_by'  => 'company_id:42',
    'expires_at' => now()->addHour()->timestamp,
]);

// Use a (pre-)generated scoped key for subsequent searches.
Todo::setScopedApiKey($scopedKey)->search('todo')->get();
```

You can also create new API keys server-side:

```php
app(\Siberfx\Typesense\Typesense::class)->createApiKey([
    'description' => 'Search-only key',
    'actions'     => ['documents:search'],
    'collections' => ['*'],
]);
```

### Filtering

Standard Scout `where`, `whereIn` and `whereNotIn` clauses are supported:

```php
Todo::search('shoes')
    ->where('user_id', 1)
    ->whereIn('status', ['open', 'in_progress'])
    ->whereNotIn('team_id', [9, 10])
    ->get();
```

Scout's operator form works too: `->where('price', '>', 100)` (`=`, `!=`, `<`, `>`, `<=`, `>=`).

For range filters (or any raw Typesense filter syntax), pass an array value:

```php
Todo::search('shoes')
    ->where('price', ['>', 100])        // price:>100
    ->where('rating', ['[3..5]'])       // rating:[3..5]
    ->get();
```

Boolean values are rendered as Typesense literals (`true`/`false`) automatically.

Filter values are inserted as-is by default. If they can come from user input,
enable escaping so a value such as `a && b` cannot change the filter:

```php
// config/scout.php
'typesense' => [
    // ...
    'escape_filter_values' => true,
],
```

String values are then wrapped in backticks (`` name:=`a && b` ``); numeric
strings, numbers, booleans and operator arrays such as `['>', 100]` are left
unquoted. For hand-written filters use
`TypesenseEngine::escapeFilterValue($value)`.

### Vector / Hybrid Search

Search a vector field by nearest neighbours. Use `nearestNeighbors()` to build
the `vector_query` for you:

```php
// Pure vector search: query '*' plus a vector field.
Todo::search('*')
    ->nearestNeighbors('embedding', [0.12, 0.34, 0.56], k: 10)
    ->get();

// Hybrid search: combine a text query with a vector query.
Todo::search('running shoes')
    ->nearestNeighbors('embedding', $vector, k: 20, distanceThreshold: 0.3, alpha: 0.4)
    ->get();
```

Or pass a raw `vector_query` string for full control:

```php
Todo::search('*')
    ->vectorQuery('embedding:([0.12, 0.34, 0.56], k:10, alpha:0.4)')
    ->get();
```

Vector searches are sent in the request body (via multi-search), so large
embeddings don't exceed Typesense's query string length limit.

### Semantic Search with Scout's `semantic()` / `hybrid()`

Scout 11.7+ has `semantic()` and `hybrid()` builder methods, and this engine
supports them. You pass the search text and the engine builds the vector query:

```php
Todo::search('things to do before the trip')->semantic()->get();
Todo::search('things to do before the trip')->semantic(minSimilarity: 0.7)->get();

// Keyword + semantic, weighted 1:2 (Typesense alpha = 2/3).
Todo::search('trip checklist')->hybrid(textWeight: 1, semanticWeight: 2)->get();
```

Tell the engine which field holds the embedding. Set this in `config/scout.php`
(same format as Scout's own Typesense driver):

```php
'typesense' => [
    // ...
    'model-settings' => [
        App\Models\Todo::class => [
            'embedding' => [
                'driver'    => 'typesense', // or 'laravel-ai'
                'attribute' => 'embedding',
            ],
        ],
    ],
],
```

You can also define `typesenseEmbeddingSettings(): ?array` on the model; it
takes precedence over the config.

**`typesense` driver:** Typesense creates the embeddings itself. Declare an
auto-embedding field in `getCollectionSchema()`:

```php
['name' => 'embedding', 'type' => 'float[]', 'embed' => [
    'from' => ['title', 'description'],
    'model_config' => ['model_name' => 'ts/all-MiniLM-L12-v2'],
]],
```

**`laravel-ai` driver:** embeddings are generated in PHP through the
[Laravel AI SDK](https://github.com/laravel/ai) (`composer require laravel/ai`).
Add `'dimensions' => 1536` (plus optional `'provider'` / `'model'`) to the
settings, declare `['name' => 'embedding', 'type' => 'float[]', 'num_dim' => 1536]`
in the schema, and tell the model what to embed:

```php
public function toSearchableEmbedding(): string|array
{
    return $this->title . "\n" . $this->description; // or a precomputed vector
}
```

The query is embedded the same way. To reuse a vector you already have, pass
`->options(['vector' => $vector])`.

Semantic and hybrid searches:

- always leave the embedding field out of the returned hits;
- turn `minSimilarity` (0..1) into `distance_threshold: 1 - minSimilarity`;
- can't be combined with `nearestNeighbors()` / `vectorQuery()` on the same query.

Hybrid searches need at least one keyword field in `typesenseQueryBy()`.

### Synonyms, Curation, Aliases & Analytics

Collection-level admin operations are available on the `Typesense` instance
(resolve it from the container or via the `Typesense` facade).

```php
use Siberfx\Typesense\Typesense;

$typesense = app(Typesense::class);

// Synonyms (per collection)
$typesense->upsertSynonym('todos', 'coat-synonyms', [
    'synonyms' => ['blazer', 'coat', 'jacket'],
]);
$typesense->retrieveSynonyms('todos');
$typesense->retrieveSynonym('todos', 'coat-synonyms');
$typesense->deleteSynonym('todos', 'coat-synonyms');

// Curation / overrides (per collection)
$typesense->upsertOverride('todos', 'promote-tidy', [
    'rule'     => ['query' => 'tidy', 'match' => 'exact'],
    'includes' => [['id' => '123', 'position' => 1]],
]);
$typesense->retrieveOverrides('todos');
$typesense->retrieveOverride('todos', 'promote-tidy');
$typesense->deleteOverride('todos', 'promote-tidy');

// Collection aliases
$typesense->upsertAlias('todos', ['collection_name' => 'todos_v2']);
$typesense->retrieveAliases();
$typesense->retrieveAlias('todos');
$typesense->deleteAlias('todos');

// Analytics rules
$typesense->upsertAnalyticsRule('popular-todos', [
    'type'   => 'popular_queries',
    'params' => [/* ... */],
]);
$typesense->retrieveAnalyticsRules();
$typesense->retrieveAnalyticsRule('popular-todos');
$typesense->deleteAnalyticsRule('popular-todos');
```

### Presets, Stopwords, Stemming, Conversation & NL Models

```php
use Siberfx\Typesense\Typesense;

$typesense = app(Typesense::class);

// Search presets
$typesense->upsertPreset('listing', ['value' => ['query_by' => 'name']]);
$typesense->retrievePresets();
$typesense->retrievePreset('listing');
$typesense->deletePreset('listing');

// Stopwords
$typesense->upsertStopword('common', ['stopwords' => ['a', 'the'], 'locale' => 'en']);
$typesense->retrieveStopwords();
$typesense->retrieveStopword('common');
$typesense->deleteStopword('common');

// Stemming dictionaries (no delete endpoint)
$typesense->upsertStemmingDictionary('plurals', [['word' => 'people', 'root' => 'person']]);
$typesense->retrieveStemmingDictionaries();
$typesense->retrieveStemmingDictionary('plurals');

// Conversation models (conversational / RAG search)
$typesense->createConversationModel([
    'model_name'         => 'openai/gpt-3.5-turbo',
    'api_key'            => 'OPENAI_API_KEY',
    'history_collection' => 'conversation_store',
]);
$typesense->retrieveConversationModels();
$typesense->retrieveConversationModel('model-id');
$typesense->updateConversationModel('model-id', ['system_prompt' => '...']);
$typesense->deleteConversationModel('model-id');

// Natural language search models
$typesense->createNLSearchModel(['model_name' => 'openai/gpt-4', 'api_key' => '...']);
$typesense->retrieveNLSearchModels();
$typesense->retrieveNLSearchModel('nl-model-id');
$typesense->updateNLSearchModel('nl-model-id', [/* ... */]);
$typesense->deleteNLSearchModel('nl-model-id');
```

## Standalone Typesense (without Scout)

Love using Scout with your Eloquent models, but every now and then you just want
to **talk to Typesense directly**? Maybe you're indexing data that isn't a model,
building a collection schema by hand, running a federated `multi_search`, or
reaching an admin API (keys, aliases, presets, analytics…) that Scout doesn't
expose. That's exactly what this is for.

Meet **`TypesenseDirect`** — a friendly, Scout-free way to use the raw Typesense
client, with a clean helper API on top and multi-cluster support built in.

> [!NOTE]
> This lives happily **next to** the Scout driver and shares nothing with it at
> runtime. Reaching for `TypesenseDirect` never changes how your models'
> `Model::search()` behaves — use whichever fits the moment.

**Which one do I want?**

| You want to… | Use |
|---|---|
| Search your Eloquent models the Laravel way | Scout (`Model::search(...)`) |
| Index/search data that isn't a model, or build schemas by hand | `TypesenseDirect` |
| Bulk-import, federated `multi_search`, or talk to a 2nd cluster | `TypesenseDirect` |
| Manage keys / aliases / presets / analytics / stopwords | `TypesenseDirect` |
| Drop down to the raw `\Typesense\Client` | `TypesenseDirect::connection()->client()` |

### Your first search in 60 seconds ⏱️

Already using the Scout driver? Then there's **nothing to configure** — the
`default` connection reuses your existing Typesense credentials. Copy this into a
route, `tinker`, or a command and run it:

```php
// 1. Create a collection
TypesenseDirect::ensureCollection([
    'name'   => 'books',
    'fields' => [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'year',  'type' => 'int32'],
    ],
    'default_sorting_field' => 'year',
]);

// 2. Add some documents (bulk)
TypesenseDirect::documents('books')->import([
    ['id' => '1', 'title' => 'Dune',        'year' => 1965],
    ['id' => '2', 'title' => 'Neuromancer', 'year' => 1984],
], 'upsert');

// 3. Search 🎉
$results = TypesenseDirect::search('books', [
    'q'        => 'dune',
    'query_by' => 'title',
]);

echo $results['found'];                       // 1
echo $results['hits'][0]['document']['title']; // "Dune"
```

That's the whole loop: **create → import → search**. Everything below is just
more of the same surface, one topic at a time.

### Setup

Nothing is required to get started: the `default` connection inherits
`scout.typesense.client-settings`, so any app already using the Scout driver has
standalone access immediately. Publish the config only when you want to define
extra connections or override values:

```bash
php artisan vendor:publish --tag=typesense-config
```

```php
// config/typesense.php
return [
    'default' => env('TYPESENSE_CONNECTION', 'default'),

    'connections' => [
        // Inherits scout.typesense.client-settings for anything left null.
        'default' => [
            'api_key'      => env('TYPESENSE_API_KEY'),
            'nodes'        => [ /* host/port/protocol … */ ],
            'nearest_node' => null,
            // timeouts/retries default to null -> inherit scout settings
        ],

        // A second, fully-specified cluster.
        'analytics' => [
            'api_key' => env('TYPESENSE_ANALYTICS_KEY'),
            'nodes'   => [
                ['host' => 'analytics.example.com', 'port' => '443', 'protocol' => 'https'],
            ],
        ],
    ],
];
```

> [!TIP]
> The Scout fallback applies **only** to the `default` connection, so existing
> apps get standalone access for free. Named connections (like `analytics`
> above) are fully independent and must be spelled out completely.

### Three ways to reach it

Pick whatever reads best where you are — they all end up at the same place:

```php
use Siberfx\Typesense\Standalone\TypesenseManager;

// 1. Facade — proxies to the default connection
TypesenseDirect::search('books', ['q' => 'dune', 'query_by' => 'title']);

// 2. A specific named connection
TypesenseDirect::connection('analytics')->search('events', ['q' => '*']);

// 3. Container / dependency injection
$ts = app('typesense.manager');           // or: app(TypesenseManager::class)
$ts->connection()->listCollections();
```

### Collections

```php
$connection = TypesenseDirect::connection();      // default connection

// Create a collection by hand
$connection->createCollection([
    'name'   => 'books',
    'fields' => [
        ['name' => 'title',  'type' => 'string'],
        ['name' => 'author', 'type' => 'string', 'facet' => true],
        ['name' => 'year',   'type' => 'int32',  'sort'  => true],
    ],
    'default_sorting_field' => 'year',
]);

// Create only if missing (retrieve-or-create)
$connection->ensureCollection([ 'name' => 'books', 'fields' => [/* … */] ]);

$connection->hasCollection('books');              // bool
$connection->retrieveCollection('books');         // schema + stats
$connection->listCollections();                   // all collections

// Add a field (alter)
$connection->alterCollection('books', [
    'fields' => [['name' => 'in_stock', 'type' => 'bool']],
]);

$connection->dropCollection('books');             // delete the collection
```

### Documents

`documents(string $collection)` returns a small helper bound to one collection:

```php
$docs = TypesenseDirect::documents('books');

$docs->create(['id' => '1', 'title' => 'Dune', 'year' => 1965]);
$docs->upsert(['id' => '1', 'title' => 'Dune', 'year' => 1965]); // create or replace
$docs->update(['id' => '1', 'year' => 1966]);                    // partial update
$docs->retrieve('1');                                            // single document
$docs->delete('1');                                              // by id

// Bulk import — array of docs OR a JSONL string.
// $action: 'create' | 'upsert' | 'update' | 'emplace'
$results = $docs->import([
    ['id' => '1', 'title' => 'Dune',        'year' => 1965],
    ['id' => '2', 'title' => 'Neuromancer', 'year' => 1984],
], 'upsert');

// Delete many by filter
$docs->deleteByFilter(['filter_by' => 'year:<1950']);

// Export the whole collection as a JSONL string
$jsonl = $docs->export();
```

### Searching

```php
// Single search
$hits = TypesenseDirect::search('books', [
    'q'         => 'dune',
    'query_by'  => 'title',
    'filter_by' => 'year:>1900',
    'sort_by'   => 'year:desc',
    'per_page'  => 20,
]);
echo $hits['found'];                 // hit count
$hits['hits'][0]['document'];        // the matched document

// Federated multi-search — pass the list of searches; the second arg holds
// parameters common to all of them. Results come back under $res['results'].
$res = TypesenseDirect::multiSearch(
    [
        ['collection' => 'books',   'q' => 'dune',   'query_by' => 'title'],
        ['collection' => 'authors', 'q' => 'herbert','query_by' => 'name'],
    ],
    ['per_page' => 5] // common params
);
$res['results'][0]['hits'];
```

### Scoped search API keys

Generate a scoped key that embeds search parameters (e.g. a tenant `filter_by`
and/or an `expires_at`). Computed locally via HMAC — no API call:

```php
$scoped = TypesenseDirect::generateScopedSearchKey($parentSearchKey, [
    'filter_by'  => 'company_id:42',
    'expires_at' => now()->addDay()->timestamp,
]);
```

### Admin resources

These accessors return the **native** `typesense-php` resource objects, so the
full underlying API is available:

```php
$c = TypesenseDirect::connection();

// API keys
$c->keys()->create(['description' => 'search-only', 'actions' => ['documents:search'], 'collections' => ['*']]);

// Collection aliases
$c->aliases()->upsert('books', ['collection_name' => 'books_v2']);

// Search presets
$c->presets()->upsert('popular', ['value' => ['query_by' => 'title', 'sort_by' => '_text_match:desc']]);

// Stopwords (note: this resource uses put/get/getAll/delete)
$c->stopwords()->put(['name' => 'stw_en', 'stopwords' => ['a', 'the'], 'locale' => 'en']);

// Stemming dictionaries
$c->stemming()->dictionaries()->upsert('irregulars', [['word' => 'people', 'root' => 'person']]);

// Analytics rules
$c->analytics()->rules()->upsert('popular_queries', ['type' => 'popular_queries', 'params' => [/* … */]]);

// Conversation (RAG) & natural-language search models
$c->conversations()->getModels()->retrieve();
$c->nlSearchModels()->retrieve();

// Cluster ops
$c->health()->retrieve();
$c->metrics()->retrieve();
$c->operations();
$c->debug();
```

### Raw client escape hatch

For anything not wrapped (e.g. per-collection synonyms/overrides), reach the
underlying `\Typesense\Client` directly:

```php
$client = TypesenseDirect::connection()->client();

$client->getCollections()['books']->getSynonyms()->upsert('coat-synonyms', [
    'synonyms' => ['blazer', 'coat', 'jacket'],
]);
```

### API summary

| Area | Methods on `TypesenseDirect::connection()` |
|------|--------------------------------------------|
| Collections | `createCollection`, `ensureCollection`, `hasCollection`, `retrieveCollection`, `alterCollection`, `dropCollection`, `listCollections` |
| Documents (`documents($c)->`) | `create`, `upsert`, `update`, `retrieve`, `delete`, `deleteByFilter`, `import`, `export` |
| Search | `search`, `multiSearch` |
| Keys | `keys`, `generateScopedSearchKey` |
| Admin | `aliases`, `presets`, `stopwords`, `stemming`, `analytics`, `analyticsV1`, `synonymSets`, `curationSets`, `conversations`, `nlSearchModels` |
| Ops | `health`, `metrics`, `debug`, `operations` |
| Escape hatch | `client()` |

> [!NOTE]
> This standalone client targets `typesense/typesense-php ^6` (server **v30+**).
> That unlocks the global resources below; on older servers, pin the client to
> `^5` and use the `client()` escape hatch instead.

### Global synonym & curation sets (v6)

Typesense v30 promotes synonyms and curation to **shareable, top-level
resources**, reachable directly from the connection:

```php
$c = TypesenseDirect::connection();

// Global synonym set (link to a collection via its `synonym_sets` field)
$c->synonymSets()->upsert('clothing', [
    'items' => [
        ['id' => 'coats', 'synonyms' => ['blazer', 'coat', 'jacket']],
    ],
]);

// Global curation set (link via a collection's `curation_sets` field)
$c->curationSets()->upsert('promos', [
    'items' => [[
        'id'       => 'apple-promo',
        'rule'     => ['query' => 'apple', 'match' => 'exact'],
        'includes' => [['id' => '422', 'position' => 1]],
        'excludes' => [['id' => '287']],
    ]],
]);

// Legacy analytics surface (alongside analytics())
$c->analyticsV1()->rules()->retrieve();
```

## Migrating from siberfx/laravel-typesense
- Replace `siberfx/laravel-typesense` in your composer.json requirements with `siberfx/typesense-scout`
- The Scout driver is now called `typesense`, instead of `typesensesearch`. This should be reflected by setting the SCOUT_DRIVER env var to `typesense`,
  and changing the config/scout.php config key from `typesensesearch` to `typesense`
- Instead of models implementing `Siberfx\Typesense\Interfaces\TypesenseSearch`, they should implement `Siberfx\Typesense\Interfaces\TypesenseDocument`

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite has two groups:

- **Unit** — pure logic (filter building, scoped key generation, config shape,
  public API surface). No server required.
- **Integration** — end-to-end flows against a real Typesense server
  (collections, documents, filtered search, synonyms, overrides, aliases,
  presets, stopwords). These are **skipped automatically** when no server is
  reachable. Point them at a server via environment variables:

  ```bash
  TYPESENSE_HOST=localhost TYPESENSE_PORT=8108 \
  TYPESENSE_PROTOCOL=http TYPESENSE_API_KEY=xyz \
  vendor/bin/phpunit --testsuite Integration
  ```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what changed in each release.

## Authors

- [Selim Görmüş](https://siberfx.com) — maintainer

Originally based on the [Typesense Laravel Scout driver](https://github.com/typesense/laravel-scout-typesense-driver)
by Typesense, Inc.

## License

The MIT License (MIT). Please see the [License File](LICENSE) for more information.
