# Changelog

All notable changes to `syriable/laravel-translator` will be documented in this file.


---

## v1.0.2 - 2026-04-11

**Full Changelog**: https://github.com/outegram/laravel-translator/compare/v1.0.1...v1.0.2

## [v1.0.2] — 2026-04-11

**Full Changelog**: https://github.com/outegram/laravel-translator/compare/v1.0.1...v1.0.2

### Fixed

- **Facade singleton bug (critical)** — `TranslatorServiceProvider::registerContracts()` previously used `$this->app->bind()` for the three public contracts, which silently created a new transient instance on every resolution. This meant `app(AITranslationServiceContract::class)`, `Translator::estimate()`, and `app(AITranslationService::class)` returned different objects, bypassing the singleton and rebuilding the `TranslationProviderManager` driver cache on every facade call. The fix replaces all three `bind()` calls with `alias()`.
  
- **N+1 query in `resolveUntranslatedKeys()` (critical)** — `AITranslateCommand` used `cursor()` with `with()` for key discovery. Laravel's `cursor()` streams models one at a time via a generator; the `with()` eager-loading constraint is ignored, causing one query per model for `translations` and `group` relationships (O(2n + 1) queries for n keys). The fix uses `chunkById(500)`, which correctly applies eager loading per chunk and reduces query count to O(ceil(n/500) × 3) regardless of dataset size.
  
- **Race condition in `persistTranslations()` (critical)** — AI translation results were persisted using a combination of `insert()` and `saveQuietly()` outside of a transaction. Concurrent queue workers processing the same language could both see `null` for the same key and both attempt `insert()`, causing a unique-constraint violation that killed the job silently. The fix wraps all writes in `DB::transaction()` and replaces insert/saveQuietly with a single `upsert()`.
  
- **Global config mutation in `clearTranslationCache()` (critical)** — The `--fresh-cache` flag previously called `config(['translator.ai.cache.enabled' => false])`, mutating the shared runtime configuration for all subsequent code in the same process. In Octane or long-running queue workers, this permanently disabled AI caching for the worker's lifetime. The fix adds `bool $bypassCache = false` as an explicit parameter to `AITranslationService::translate()` and propagates it through the call chain without touching global config.
  
- **Memory leak in `TranslationPromptBuilder` (medium)** — The prompt builder stored `$languageNameCache` and `$translationMemoryCache` as instance-level arrays on a singleton. In long-running processes, these arrays grew without bound and never reflected changes (e.g. newly approved translations) made during the worker's lifetime. Both arrays are replaced with `Cache::remember()` calls with configurable TTLs.
  
- **Dead code in `TranslationProviderManager`** — The unused `?Container $container` constructor parameter is removed.
  

### Added

- **`DatabaseTranslationLoader`** — A runtime translation loader that integrates with Laravel's `__()` helper and the `Lang` facade, serving translations directly from the database. Enable with `TRANSLATOR_LOADER_ENABLED=true`. Wraps the existing file loader with a configurable fallback (`TRANSLATOR_LOADER_FALLBACK=true`). All results are cached with a configurable TTL (`TRANSLATOR_LOADER_CACHE_TTL=3600`). Invalidated automatically by `TranslationObserver` on every Translation save/delete.
  
- **`TranslationObserver`** — Watches Translation model events (`saved`, `deleted`) and invalidates two cache layers: the `DatabaseTranslationLoader` cache for the affected locale/group, and the `TranslationPromptBuilder` translation memory cache when a translation reaches `Reviewed` status.
  
- **`translator:coverage` command** — Shows translation coverage (translated %, reviewed %) per language. Supports `--locale`, `--group`, `--min` (warn below threshold), `--fail-below` (CI exit code gate), and `--format=json|csv|table` output modes.
  
- **`translator:languages` command** — Lists all registered languages with code, name, native name, RTL flag, active status, and source flag. Supports `--active`, `--with-coverage`, and `--format=json`.
  
- **`translator:review` command** — Bulk-marks `Translated` translations as `Reviewed` for a locale, making them eligible for AI translation memory. Supports `--group`, `--dry-run`, `--force`. Automatically invalidates the AI prompt memory cache after a bulk update (which bypasses model events).
  
- **`translator:diff` command** — Compares database translations against on-disk lang files for a given locale. Reports DB-only keys, file-only keys, and value differences. Supports `--group`, `--show-db-only`, `--show-file-only`, `--show-changed`, and `--format=json`.
  
- **`translator:provider-check` command** — Validates AI provider configuration (config block presence, API key), driver availability, and optionally sends a live API ping (`--ping`). Supports `--provider=name` and `--all`.
  
- **`Bus::batch()` for queue dispatch** — `translator:ai-translate --queue` now dispatches a named Laravel Bus batch instead of individual jobs. Provides Horizon batch visibility, progress tracking, `allowFailures()` semantics, and the `bypassCache` flag flows through to each job without config mutation.
  
- **`TranslateKeysJob::make()` factory** — Static factory for cleaner dispatch syntax. The job also gains the `Batchable` trait for Bus batch support and a batch cancellation guard in `handle()`.
  
- **`--no-lock` flag for `translator:ai-translate`** — Skips concurrency lock acquisition, useful in CI environments that intentionally run parallel language batches.
  
- **Concurrency lock** — `translator:ai-translate` now acquires a per-language `Cache::lock()` before proceeding, preventing two simultaneous runs for the same language from duplicating API calls.
  
- **ChatGPT driver enabled** — The `chatgpt` provider config block is now uncommented in the default config. Set `OPENAI_API_KEY` to activate it. Full `Http::fake()` tests added for both `ClaudeDriver` and `ChatGptDriver`.
  
- **Versioned migration pattern** — A second migration stub (`add_loader_index_to_translator_translations.php.stub`) demonstrates the additive migration convention used for future schema changes. Base migration is unchanged.
  
- **`translator.loader` config block** — New configuration keys: `enabled`, `fallback_to_files`, `cache_ttl`, `cache_prefix`.
  
- **`translator.ai.translation_memory.cache_ttl`** — Configures the TTL for the per-locale translation memory cache (replaces the removed instance-level array).
  
- **`translator.ai.translation_memory.lang_name_cache_ttl`** — Configures the TTL for language name lookups in the prompt builder.
  

### Changed

- **PHPStan upgraded from level 5 to level 8** — All new code is written to pass level 8 analysis. Existing code is covered by the baseline.
  
- **`AITranslationServiceContract::translate()` signature** — Added `bool $bypassCache = false` parameter (backward-compatible default). All implementing classes and callers are updated.
  
- **`config/translator.php`** — ChatGPT provider block is now uncommented. New `loader` section. New `translation_memory.cache_ttl` and `translation_memory.lang_name_cache_ttl` keys.
  


---

## [v1.0.1] — 2026-04-10

### Added

- **`translator:prune-logs` command** — Automatic log pruning via Laravel scheduler (weekly).
- **`AITranslationCompleted` event** — Dispatched after every AI translation execution.
- **Translation memory in AI prompts** — `TranslationPromptBuilder` injects reviewed translations as context.
- **Plural form awareness** — `PluralFormProvider` enriches AI prompts with CLDR plural form counts.
- **`PluralFormProvider`** — Static CLDR-based lookup for 100+ locales.
- **`Translator` Facade** — `Syriable\Translator\Facades\Translator` now resolves correctly.
- **Public API contracts** — Three thin interfaces under `Syriable\Translator\Contracts\`.
- **`translator:scan` command** — Static source-code scanner with `--sync` and `--purge-orphans`.

### Changed

- **`AITranslationService::persistTranslations()`** — Bulk strategy replacing N+1.
- **Default Claude model** — Unified to `claude-haiku-4-5-20251001`.
- **`FileWalker`** — Replaced `array_any()` with `foreach` for PHP 8.4 compatibility.

### Fixed

- **`array_any()` crash on PHP 8.4** — Hard runtime crash resolved.
- **Missing `Translator` Facade class** — Class-not-found error resolved.


---

## [v1.0.0] — 2026-04-09

### Added

- First stable release of **Syriable Laravel Translator** (`syriable/laravel-translator`).
- Database-backed translation groups, keys, languages, and values.
- Import/export pipeline for PHP and JSON lang files.
- AI translation via Claude and ChatGPT with cost estimation and queue support.
- Artisan commands: import, export, AI translate, AI stats, queue diagnostics.
- Validation rules for translation parameters and plural forms.
