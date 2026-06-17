# Changelog

All notable changes to `siberfx/typesense-scout` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- Laravel 13 support.
- `whereNotIn()` support in the search engine — Scout's `whereNotIn` now
  produces a Typesense `field:!=[...]` filter (previously silently ignored).
- Test suite: PHPUnit harness with unit coverage for filter generation and the
  published config shape.

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
