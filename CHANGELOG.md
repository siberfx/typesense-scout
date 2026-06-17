# Changelog

All notable changes to `siberfx/typesense-scout` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- Laravel 13 support.

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
