# Changelog

All notable changes to `siberfx/typesense-scout` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [1.7.1] - 2026-10-05

### Fixed
- README Tests badge showed "no status" after successful runs: GitHub's workflow
  badge reports no status whenever a `branch=` filter is set. It now filters by
  `event=push` (tests only run on pushes to `main`, so the result is the same).

## [1.7.0] - 2026-10-05

### Security
- Raised the Laravel floor above every release covered by a published advisory:
  `illuminate/*` now requires `^12.69|^13.30` (was `^12.0|^13.0`). This excludes
  versions affected by CVE-2026-102279 (XSS in debug page information,
  `<12.69.0` / `<13.30.0`), CVE-2026-48019 (CRLF injection in the default email
  rule), the temporary signed URL path confusion advisory (`<12.61.1` /
  `<13.12.0`) and CVE-2025-27515 (file validation bypass). Because
  `laravel/framework` replaces the `illuminate/*` packages, Composer now refuses
  to install the driver next to a vulnerable framework release.

### Changed
- Require `laravel/scout` `^11.8` (was `^11.7`), matching the Scout 11.8 features
  already ported into the engine.
- Dev dependencies: `phpunit/phpunit` `^13.4`, `vlucas/phpdotenv` `^5.7`,
  `php-http/guzzle7-adapter` `^1.1`. `composer audit` reports no advisories for
  either the lowest or the highest dependency set.
- README: the Tests badge now uses GitHub's native workflow badge; added a Laravel badge,
  a Requirements table and a Changelog section; replaced the upstream
  "development paused" notice with a description of this package; fixed the
  Migrating anchor, the License link and the Authors section; refreshed the
  installation note (Laravel 12/13 ship Guzzle 7).
- LICENSE: added the maintainer's copyright line alongside the original
  Typesense, Inc notice.
- `composer.json`: homepage points to the GitHub repository; added `scout` and
  `vector-search` keywords.

### Removed
- Internal planning docs (`docs/`) are no longer tracked; `docs/`, `.superpowers/`
  and `.claude/` are now git-ignored.
- `scout-import.yml` workflow: it checked out and tested an unrelated external
  repository and never exercised this package's code.
- Redundant `suggest` entry for `typesense/typesense-php` (already a hard
  requirement) and the unused `pestphp/pest-plugin` allow-plugins entry.

### Added
- `.gitattributes` with `export-ignore` rules so Packagist dist archives ship only
  `src/`, `config/`, `composer.json`, README and LICENSE (no tests, CI or
  phpunit config).

## [1.6.1] - 2026-09-29

### Added
- Scout `semantic()` and `hybrid()` searches (ported from laravel/scout 11.7).
  The engine now implements `SupportsSemanticSearch`; previously `semantic()`
  threw `NotSupportedException` and `hybrid()` was silently ignored. Configure
  embeddings per model in `scout.typesense.model-settings.{Model}.embedding`
  (or a model `typesenseEmbeddingSettings()` method) with the `typesense`
  driver (native `embed` fields) or the `laravel-ai` driver (embeddings
  generated through the optional `laravel/ai` SDK from `toSearchableEmbedding()`).
- `scout.typesense.escape_filter_values` (opt-in): backtick-escape string values
  from `where` / `whereIn` / `whereNotIn`, so values containing `&&`, `||`, `,`
  or `]` cannot alter the filter. `TypesenseEngine::escapeFilterValue()` is
  public for hand-written filters (equivalent to typesense-php 6.1 `FilterBy::escape()`).
- Laravel 13 support.
- `whereNotIn()` support in the search engine — Scout's `whereNotIn` now
  produces a Typesense `field:!=[...]` filter (previously silently ignored).
- Comparison / range filter operators via array values, e.g.
  `where('price', ['>', 100])` => `price:>100` and
  `where('price', ['[10..100]'])` => `price:[10..100]`.
- Boolean filter values now render as Typesense literals (`true`/`false`)
  instead of `1`/`0`, via a new `parseFilterValue()` applied to all where
  clauses.
- Real Typesense scoped search keys: `Typesense::generateScopedSearchKey()`
  (and the `HasScopedApiKey::generateScopedSearchKey()` helper) produce an
  HMAC-signed key embedding search parameters such as a tenant `filter_by` or
  `expires_at`. Added `Typesense::createApiKey()` for API key creation.
- Vector / hybrid semantic search: `nearestNeighbors($field, $vector, $k, ...)`
  builds a Typesense `vector_query`, and `vectorQuery($raw)` accepts a raw
  string for full control. Both are chainable on the Scout builder. Pure vector
  search uses `search('*')`; supplying a text query performs a hybrid search.
- Admin API wrappers on `Typesense` (also available via the facade):
  - Synonyms: `upsertSynonym`, `retrieveSynonyms`, `retrieveSynonym`, `deleteSynonym`.
  - Curation / overrides: `upsertOverride`, `retrieveOverrides`, `retrieveOverride`, `deleteOverride`.
  - Collection aliases: `upsertAlias`, `retrieveAliases`, `retrieveAlias`, `deleteAlias`.
  - Analytics rules: `upsertAnalyticsRule`, `retrieveAnalyticsRules`, `retrieveAnalyticsRule`, `deleteAnalyticsRule`.
  - Search presets: `upsertPreset`, `retrievePresets`, `retrievePreset`, `deletePreset`.
  - Stopwords: `upsertStopword`, `retrieveStopwords`, `retrieveStopword`, `deleteStopword`.
  - Stemming dictionaries: `upsertStemmingDictionary`, `retrieveStemmingDictionaries`, `retrieveStemmingDictionary`.
  - Conversation models (RAG): `createConversationModel`, `retrieveConversationModels`, `retrieveConversationModel`, `updateConversationModel`, `deleteConversationModel`.
  - Natural language search models: `createNLSearchModel`, `retrieveNLSearchModels`, `retrieveNLSearchModel`, `updateNLSearchModel`, `deleteNLSearchModel`.
- Test suite: PHPUnit harness with unit coverage for filter generation,
  filter-value normalisation, scoped key generation, and the published config
  shape.
- Guarded integration test suite (`tests/Integration`) that exercises real
  end-to-end flows (collections, documents, filtered search, synonyms,
  overrides, aliases, presets, stopwords) against a live Typesense server, and
  skips automatically when none is reachable (configurable via `TYPESENSE_*`
  environment variables).
- Standalone Typesense subsystem (no Scout): `TypesenseManager`, `TypesenseConnection`,
  `DocumentActions`, `TypesenseConnectionFactory`, and the `TypesenseDirect` facade.
  Named multi-connection support via a new publishable `config/typesense.php`; the
  `default` connection inherits `scout.typesense.client-settings`.
- v6 global resources on the standalone client: `TypesenseDirect::synonymSets()`,
  `curationSets()`, and `analyticsV1()` (Typesense server 30+).

### Deprecated
- Per-collection synonym and curation/override methods on `Typesense`
  (`upsertSynonym`, `retrieveSynonyms`, `retrieveSynonym`, `deleteSynonym`,
  `upsertOverride`, `retrieveOverrides`, `retrieveOverride`, `deleteOverride`).
  Typesense server **v30 removed the per-collection synonyms/curation
  endpoints** (they now 404); use the global synonym sets / curation sets
  instead — `TypesenseDirect::synonymSets()` / `curationSets()`. The methods are
  kept for talking to servers older than v30 and will be removed in a future
  major release. The admin integration tests now exercise the global resources.

### Fixed
- `where()` clauses produced invalid filters on Scout 11 (e.g. `0:...`): Scout 11
  stores them as `[field, operator, value]` entries, which the engine read as
  `field => value` pairs. Both shapes are now handled, and Scout's operator form
  `where('price', '>', 100)` (`=`, `!=`, `<`, `>`, `<=`, `>=`) is supported.
- Vector searches (`nearestNeighbors()` / `vectorQuery()`) are sent in the POST
  body of a multi-search instead of the GET query string, where a typical
  768-1536 dimension embedding exceeded Typesense's query string length limit.
  Multi-search error entries are rethrown as the matching Typesense exception.
- Search parameters with legitimate falsy values are no longer stripped before
  the request: `enableOverrides(false)` and `setPrioritizeExactMatch(false)`
  previously vanished from the query (silently reverting to server defaults),
  and an empty search string (`Model::search('')`, a filter-only search)
  dropped the required `q` parameter entirely.
- Per-query engine state (groupBy, facetBy, vector/multi-search options,
  highlight settings, ...) is now reset after every search. The engine is a
  long-lived singleton, so options set on one search previously leaked into
  every subsequent search — including other requests in queue workers and
  Octane. A stale `searchMulti()` would even turn later searches into
  multi-searches.
- `update()` (indexing): decides soft-delete handling per model instead of
  from the first model of the batch (a mixed batch could be skipped or
  imported wholesale), skips models whose `toSearchableArray()` is empty
  (previously imported an empty document and failed), and no-ops on an empty
  model collection instead of erroring.
- `mapIds()` and `lazyMap()` now handle grouped (`group_by`) search responses;
  previously they read the absent `hits` key and returned no ids.
- `performSearch()`: removed a duplicated code block that issued a redundant
  collection lookup (an extra HTTP round-trip on every multi-search).
- `Typesense::setScopedApiKey()` now evicts the cached engine before
  re-registering it; previously it was a silent no-op once any search had
  already resolved the engine.
- `Typesense::deleteDocument()` no longer swallows every exception: network or
  auth failures now surface instead of returning an empty success (which left
  the index silently out of sync). Deleting an already-missing document
  remains a no-op, now via a single HTTP call instead of retrieve-then-delete.
- `Typesense::upsertDocument()` uses Typesense's native upsert instead of a
  non-atomic retrieve → delete → create sequence (three HTTP calls, and a
  failure mid-sequence could lose the document).
- Config mismatch: the published `config/scout.php` now nests Typesense
  connection settings under `typesense.client-settings`, the key the service
  provider and `Typesense` class actually read. Previously the shipped config
  was flat, so `new Client(null)` would fail out of the box. Settings now also
  honour `TYPESENSE_*` environment variables.

### Changed
- Require `laravel/scout` `^11.7` (for `SupportsSemanticSearch`). `laravel/ai` is
  suggested for the `laravel-ai` embedding driver.
- `TypesenseEngine` now receives the `scout.typesense` config as a second
  (optional) constructor argument.
- Upgraded the dev test toolchain to PHPUnit 13: `phpunit/phpunit` constraint
  bumped from `^11.5|^12.0` to `^13.0`, and `config.platform.php` pinned to
  `8.4.1` (PHPUnit 13's minimum PHP, still within the package's `^8.4`).
- README: documented the full standalone (`TypesenseDirect`) API — collections,
  documents, single & federated search, scoped keys, admin resources, cluster
  ops, and the raw `client()` escape hatch — with a beginner-friendly
  "first search in 60 seconds" guide, a Scout-vs-standalone comparison, and an
  API summary table.
- CI workflows aligned with the codebase: set up PHP 8.4, test the dependency
  range via a `lowest`/`highest` matrix (Laravel 12 and 13), bump Typesense to
  27.1, update actions to current major versions, drop the unused Node matrix,
  and run the MySQL-backed project test against a MySQL service container.
- Require PHP `^8.4`.
- Resolved the `main` merge between local and remote: PHP set to `^8.4`,
  `typesense/typesense-php` set to `^5.0`.
- Pinned `laravel/scout` to `^11.0` (Scout's latest major, which supports both
  Laravel 12 and 13 — Scout versions are independent of the framework version).
- README now states support for "Laravel 12 and 13".
- Upgraded `typesense/typesense-php` to `^6.0`; the package now targets
  Typesense server **30.x**. CI runs against the `typesense/typesense:30.2`
  image.

### Removed
- Dropped Laravel 10 and 11 support: `illuminate/*` constraints narrowed from
  `^11.0|^12.0|^13.0` to `^12.0|^13.0`, and the previously invalid
  `laravel/scout` constraint (`^10.0|^11.0|^12.0|^13.0`) was corrected to `^11.0`.

[Unreleased]: https://github.com/siberfx/typesense-scout/compare/1.7.1...HEAD
[1.7.1]: https://github.com/siberfx/typesense-scout/compare/1.7.0...1.7.1
[1.7.0]: https://github.com/siberfx/typesense-scout/compare/1.6.1...1.7.0
[1.6.1]: https://github.com/siberfx/typesense-scout/compare/1.5.0...1.6.1
