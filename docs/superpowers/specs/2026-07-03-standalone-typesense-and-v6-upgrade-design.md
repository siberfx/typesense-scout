# Standalone Typesense subsystem + typesense-php v6 upgrade — Design

**Date:** 2026-07-03
**Package:** `siberfx/typesense-scout`
**Author:** Selim Görmüş (info@siberfx.com), with Claude

## Problem & goal

The package is today a Laravel Scout driver. Everything that talks to Typesense is
mediated by Scout: `src/Typesense.php` wraps `\Typesense\Client`, but its
document/collection helpers take Scout `$model` objects and `setScopedApiKey()`
reaches into Scout's `EngineManager`.

We want a **first-class, Scout-independent way to talk to Typesense directly** —
build schemas by hand, bulk-import, run federated `multi_search`, and reach admin
APIs — usable via a facade / container binding, supporting **multiple named
connections** (clusters). Then, as a follow-up, **upgrade the client to v6.x**
(server 30.x) and expose the v6-only features currently unreachable.

Two decisions locked during brainstorming:

- Standalone shape → **full multi-connection subsystem** (factory + manager +
  connection + facade + config).
- Upgrade target → **bump `typesense/typesense-php` to `^6.0`** (stable v6.0.0),
  target Typesense server **30.x**.
- Config → **new publishable `config/typesense.php`; the `default` connection
  falls back to `scout.typesense.client-settings`** so existing apps need zero new
  config.
- Existing Scout wrapper → **left in place; only patch v6 breakages** (no dedup
  refactor).
- Standalone facade alias → **`TypesenseDirect`**.

## Version ground-truth (verified 2026-07-03)

| Component | Installed / current | Target |
|---|---|---|
| `typesense/typesense-php` | v5.2.0 (constraint `^5.0`) | **`^6.0`** (stable v6.0.0, 2025-12-22) |
| Typesense server | — | **30.2** |
| Package PHP | `^8.4` | unchanged |

**v6.0.0 client adds these top-level accessors** (absent in v5.2.0), verified by
diffing the client `Client.php`:

- `synonymSets` / `getSynonymSets()` — **global synonyms** (shareable across
  collections; new top-level resource in server 30).
- `curationSets` / `getCurationSets()` — **global curation rules**.
- `analyticsV1` / `getAnalyticsV1()` — legacy analytics surface alongside the
  reworked `analytics`.

These are the concrete "missing features" the upgrade unlocks.

---

## Phase 1 — Standalone subsystem (additive; Scout untouched at runtime)

### Components

New namespace `Siberfx\Typesense\Standalone`. Small, single-purpose units so no
file balloons the way `Typesense.php` (838 lines) did.

| File | Responsibility | Depends on |
|---|---|---|
| `config/typesense.php` | Named connection definitions; `default` connection. | — |
| `src/Standalone/TypesenseConnectionFactory.php` | Turn one connection config array into a `\Typesense\Client`, merging `scout.typesense.client-settings` under `default`. | `\Typesense\Client`, `config()` |
| `src/Standalone/TypesenseManager.php` | Entry point. `connection(?string $name = null): TypesenseConnection`, lazily built + cached per name. `__call` proxies to the default connection. | Factory |
| `src/Standalone/TypesenseConnection.php` | Ergonomic wrapper over ONE client: collections, search, multiSearch, admin passthroughs, ops, `client()` escape hatch. | `\Typesense\Client` |
| `src/Standalone/DocumentActions.php` | Per-collection document ops returned by `TypesenseConnection::documents($name)`. | `\Typesense\Client` |
| `src/Standalone/TypesenseStandaloneFacade.php` | Facade over `typesense.manager`. | — |

Registration **folds into the existing `TypesenseServiceProvider`** (one provider,
no new consumer wiring): binds `typesense.manager` singleton, `mergeConfigFrom` +
`publishes` the config, and registers the `TypesenseDirect` alias.

### `config/typesense.php`

```php
return [
    'default' => env('TYPESENSE_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            // Any key left null falls back to scout.typesense.client-settings.
            'api_key' => env('TYPESENSE_API_KEY'),
            'nodes'   => [
                // [ 'host' => ..., 'port' => ..., 'path' => '', 'protocol' => 'https' ]
            ],
            'nearest_node' => null,
            'connection_timeout_seconds' => 2,
            'healthcheck_interval_seconds' => 15,
            'num_retries' => 3,
            'retry_interval_seconds' => 1,
        ],
    ],
];
```

Factory rule: for a connection whose resolved settings are empty/partial, merge in
`config('scout.typesense.client-settings')` (connection value wins on conflict).
Only the `default` connection gets the Scout fallback; named clusters are explicit.

### `TypesenseConnection` public surface

- `client(): \Typesense\Client` — raw escape hatch.
- **Collections:** `createCollection(array $schema)`, `ensureCollection(array $schema)`
  (create-if-missing, catches `ObjectNotFound`), `hasCollection(string $name): bool`,
  `retrieveCollection(string $name)`, `alterCollection(string $name, array $changes)`,
  `dropCollection(string $name)`, `listCollections()`.
- **Documents:** `documents(string $collection): DocumentActions` exposing
  `create/index`, `upsert`, `update`, `retrieve`, `delete`, `deleteByFilter(array $query)`,
  `import(iterable $docs, string $action = 'upsert')`, `export()`.
- **Search:** `search(string $collection, array $params)`,
  `multiSearch(array $searches, array $common = [])`, `union(array $searches, array $common = [])`.
- **Admin passthroughs (thin):** `keys()`, `generateScopedSearchKey($searchKey, $params)`,
  `aliases()`, `presets()`, `stopwords()`, `stemming()`, `conversations()`,
  `nlSearchModels()`, and the **v6 additions** `synonymSets()`, `curationSets()`,
  `analyticsV1()`, plus `analytics()`.
- **Ops:** `health()`, `metrics()`, `debug()`, `operations()`.

Admin passthroughs return the underlying typesense-php resource objects so callers
get the full native API without us re-wrapping every method.

### Data flow

```
TypesenseDirect::search('books', $p)
  -> TypesenseManager->connection(null=default)      (cached)
    -> TypesenseConnection over \Typesense\Client     (built by Factory: config + scout fallback)
      -> typesense-php HTTP
```

### Error handling

- All operations let `\Typesense\Exceptions\*` bubble (consistent with the existing
  wrapper).
- `ensureCollection` / `hasCollection` catch `ObjectNotFound` internally and only
  there.
- README documents the status→exception map (already in the skill reference).

### Testing

Mirrors the existing `tests/Unit` + `tests/Integration` split.

- **Unit (no server):**
  - Factory builds a `\Typesense\Client` from a connection array.
  - Factory merges `scout.typesense.client-settings` into `default` and connection
    values win on conflict.
  - Manager caches one `TypesenseConnection` per name; `connection()` == default.
  - Facade resolves `typesense.manager`.
  - `generateScopedSearchKey` HMAC output shape.
- **Integration (env-guarded, reuses `IntegrationTestCase`):**
  create collection → `import` → `search` → `multiSearch` → global `synonymSets` /
  `curationSets` upsert+retrieve → `dropCollection`.

---

## Phase 2 — typesense-php v6 upgrade + missing features (own spec/plan)

Tracked separately so Phase 1 can ship independently. Outline:

1. `composer.json`: `"typesense/typesense-php": "^6.0"`; `composer update`; README
   states **server 30.x** required.
2. **Audit `src/Typesense.php` for v6 breakages** and patch minimally:
   - Analytics rework: confirm `getAnalytics()->rules()/events()` shapes vs the new
     `analyticsV1`; adjust existing `*AnalyticsRule*` methods accordingly.
   - Per-collection `getSynonyms()/getOverrides()`: confirm still present in v6 (or
     deprecated in favor of global sets) and keep the existing methods working.
3. **Expose v6-only features** through `TypesenseConnection`: global `synonymSets`,
   `curationSets`, `analyticsV1` (already in the Phase 1 surface — implement against
   the v6 client here).
4. Refresh the `typesense-standalone` skill version table + method notes; update
   `README.md` and `CHANGELOG.md`.

---

## Out of scope (YAGNI)

- No refactor of the Scout engine or `Typesense.php` for de-duplication.
- No auto-migration tooling for per-collection → global synonyms/curation.
- No new HTTP client abstraction — keep typesense-php's HTTPlug discovery.
