# Changelog

All notable changes to `siberfx/typesense-scout` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
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
- Test suite: PHPUnit harness with unit coverage for filter generation,
  filter-value normalisation, scoped key generation, and the published config
  shape.

### Fixed
- Config mismatch: the published `config/scout.php` now nests Typesense
  connection settings under `typesense.client-settings`, the key the service
  provider and `Typesense` class actually read. Previously the shipped config
  was flat, so `new Client(null)` would fail out of the box. Settings now also
  honour `TYPESENSE_*` environment variables.

### Changed
- Require PHP `^8.4`.
- Resolved the `main` merge between local and remote: PHP set to `^8.4`,
  `typesense/typesense-php` set to `^5.0`.
- Pinned `laravel/scout` to `^11.0` (Scout's latest major, which supports both
  Laravel 12 and 13 — Scout versions are independent of the framework version).
- README now states support for "Laravel 12 and 13".

### Removed
- Dropped Laravel 10 and 11 support: `illuminate/*` constraints narrowed from
  `^11.0|^12.0|^13.0` to `^12.0|^13.0`, and the previously invalid
  `laravel/scout` constraint (`^10.0|^11.0|^12.0|^13.0`) was corrected to `^11.0`.
