# Changelog

All notable changes to `syriable/laravel-translator` will be documented in this file.

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
