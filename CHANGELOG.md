# Changelog

All notable changes to `syriable/laravel-translator` will be documented in this file.

---

## [[v1.0.1] — 2026-04-010]

### Added

- **`translator:prune-logs` command** — deletes `ImportLog`, `ExportLog`, and `AITranslationLog` records older than the configured `log_retention_days` window. Supports `--days=` to override the config value and `--dry-run` to preview deletions without modifying data.
- **Automatic log pruning via scheduler** — `translator:prune-logs` is registered with the Laravel scheduler (`weekly`) when `log_retention_days > 0`. Works out of the box without any manual scheduling.
- **`AITranslationCompleted` event** — dispatched by `AITranslationService` after every AI translation execution (both API calls and full cache hits). Carries the persisted `AITranslationLog` record. Controlled by `config('translator.events.ai_translation_completed')`.
- **Translation memory in AI prompts** — `TranslationPromptBuilder` now queries previously reviewed translations for the target language and injects them into the system prompt as a `<translation_memory>` block. Improves terminology consistency across batches and separate runs. Controlled by `translator.ai.translation_memory.enabled` and `.limit` (default: 20 examples, `Reviewed` status only).
- **Plural form awareness in AI prompts** — the plural rule in the system prompt is now enriched with the exact CLDR plural form count and category names required by the target language (e.g. Arabic = 6 forms: zero, one, two, few, many, other). Prevents the common LLM failure of producing two-variant output for multi-form languages.
- **`PluralFormProvider`** — static CLDR-based lookup returning plural form counts and category names for 100+ locales. Used by `TranslationPromptBuilder` but publicly available for any locale-aware logic.
- **`Translator` Facade** — `Syriable\Translator\Facades\Translator` now resolves correctly to `AITranslationService` via the `translator` container binding. The `composer.json` alias was previously registered but the facade class was missing.
- **Public API contracts** — three thin interfaces added under `Syriable\Translator\Contracts\`:
    - `TranslationImporterContract` — declares `import(ImportOptions): ImportResult`
    - `TranslationExporterContract` — declares `export(ExportOptions): ExportResult`
    - `AITranslationServiceContract` — declares `estimate()` and `translate()`
    - All three are bound in the service provider. Companion packages should type-hint against contracts, not concrete classes.
- **`translator:scan` command** — scans source files for `__()`, `trans()`, `@lang()`, and related calls and compares them against database records. Reports missing keys (in code, not in DB) and orphaned keys (in DB, not in code). Supports `--sync`, `--purge-orphans`, `--fail-on-missing` (CI gate), `--missing-only`, and `--orphans-only`.
- **`TranslationUsageExtractor`** — extracts literal translation keys from PHP, Blade, JS, TS, and Vue source files using regex patterns for all standard Laravel translation helpers.
- **`TranslationKeyScanner`** — orchestrates the source-code vs database comparison. Exposes `parseKeyComponents()` for the `--sync` workflow.
- **`ScanResult` DTO** — immutable value object returned by `TranslationKeyScanner::scan()` carrying `usedKeys`, `missingKeys`, `orphanedKeys`, file count, and duration.
- **`translator.events.ai_translation_completed` config key** — controls whether `AITranslationCompleted` is dispatched.
- **`translator.ai.translation_memory` config block** — controls translation memory injection (`enabled`, `limit`).
- **`translator.scanner` config block** — controls source-file scan `paths`, `ignore_paths`, and `extensions` for `translator:scan`.

### Changed

- **`AITranslationService::persistTranslations()`** — replaced per-key N+1 queries with a bulk strategy: 1 query to load `TranslationKey` records by key names, 1 query to load existing `Translation` rows, then a single bulk `INSERT` for new rows and individual `saveQuietly()` only for updates. Query count reduced from O(n×2) to a constant 3 regardless of batch size.
- **`TranslationPromptBuilder::buildSystemPrompt()`** — now accepts the full `TranslationRequest` to enable translation memory and plural form injection. The private `renderExistingTranslationContext()` method is fully implemented (previously a stub returning `''`).
- **`TranslatorServiceProvider`** — now declares `declare(strict_types=1)` (was the only file in `src/` without it), registers the new commands, binds the three contracts, and registers the scheduler via `packageBooted()`.
- **Default Claude model** — unified to `claude-sonnet-4-6` across `config/translator.php`, `ClaudeDriver::resolveModel()`, and documentation. Previously three different defaults appeared across these three locations.
- **`FileWalker`** — replaced both `array_any()` usages with explicit `foreach` loops for PHP 8.4 compatibility (`array_any()` requires PHP 8.5).

### Fixed

- **`array_any()` crash on PHP 8.4** — `FileWalker::isIgnored()` and `hasAllowedExtension()` now use `foreach` + early return instead of `array_any()`. This was a hard runtime crash on all PHP 8.4 installations.
- **Missing `Translator` Facade class** — the `composer.json` alias `"Translator": "Syriable\\Translator\\Facades\\Translator"` was registered but the file did not exist, making all static facade calls fail with a class-not-found error.

---

## [v1.0.0] — 2026-04-09

### Added

- First stable release of **Syriable Laravel Translator** (`syriable/laravel-translator`).
- Database-backed translation groups, keys, languages, and values with configurable table prefix.
- Import pipeline from PHP and JSON lang files; export back to disk.
- AI translation via configurable providers (Claude, ChatGPT), estimates, caching, and queue job support.
- Artisan commands: import, export, AI translate, AI stats, queue diagnostics.
- Validation rules for translation parameters and plural forms.
- Package configuration under `config/translator.php`, events, and English validation lang lines.
