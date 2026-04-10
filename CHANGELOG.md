# Changelog

All notable changes to `syriable/laravel-translator` will be documented in this file.

## v1.0.1 - 2026-04-10

**Full Changelog**: https://github.com/outegram/laravel-translator/compare/v1.0.0...v1.0.1

## [1.0.1] - 2026-04-10

### Added

- `translator:scan` Artisan command to find translation keys used in source (`__()`, `trans()`, etc.), report keys missing from the database or orphaned in the database, optionally sync missing keys or purge orphans, and support `--fail-on-missing` for CI.
- `TranslationKeyScanner`, `TranslationUsageExtractor`, and `ScanResult` DTO backing the scan pipeline; configurable via `config('translator.scanner')`.
- Feature and unit tests for the scan command and extractor.

### Changed

- README: expanded Artisan command documentation for `translator:scan` and related usage.
- `TranslatorServiceProvider` registers scanner services (`FileWalker`, `TranslationUsageExtractor`, `TranslationKeyScanner`) as singletons.

### Fixed

- `FileWalker`: avoid `array_any()` so ignored paths and extensions work on PHP 8.3 (explicit loops; documented in PHPDoc).

## v1.0.0 - 2026-04-09

**Full Changelog**: https://github.com/outegram/laravel-translator/commits/v1.0.0

## [1.0.0] - 2026-04-09

### Added

- First stable release of **Syriable Laravel Translator** (`syriable/laravel-translator`).
- Database-backed translation groups, keys, languages, and values with configurable table prefix.
- Import pipeline from PHP and JSON lang files; export back to disk.
- AI translation via configurable providers (Claude, ChatGPT), estimates, caching, and queue job support.
- Artisan commands: import, export, AI translate, AI stats, queue diagnostics.
- Validation rules for translation parameters and plural forms.
- Package configuration under `config/translator.php`, events, and English validation lang lines.
