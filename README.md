# Syriable Translator

A production-ready Laravel translation management package that imports, exports, and AI-translates your application's language files using Claude (Anthropic), ChatGPT (OpenAI), or any custom provider you register.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Database Structure](#database-structure)
- [Core Concepts](#core-concepts)
- [Artisan Commands](#artisan-commands)
    - [translator:import](#translatorimport)
    - [translator:export](#translatorexport)
    - [translator:scan](#translatorscan)
    - [translator:ai-translate](#translatorai-translate)
    - [translator:ai-stats](#translatorai-stats)
    - [translator:queue-check](#translatorqueue-check)
    - [translator:prune-logs](#translatorprune-logs)
- [AI Translation System](#ai-translation-system)
    - [How it works](#how-it-works)
    - [Cost estimation](#cost-estimation)
    - [Translation memory](#translation-memory)
    - [Plural form awareness](#plural-form-awareness)
    - [Supported providers](#supported-providers)
    - [Adding a new AI provider](#adding-a-new-ai-provider)
    - [Switching the default provider](#switching-the-default-provider)
    - [Using the queue for large batches](#using-the-queue-for-large-batches)
    - [Translation cache](#translation-cache)
- [Facade](#facade)
- [Public Contracts](#public-contracts)
- [Models](#models)
- [Events](#events)
- [Validation Rules](#validation-rules)
- [Extending the Package](#extending-the-package)
    - [Custom models](#custom-models)
    - [Custom table prefix](#custom-table-prefix)
- [Environment Variable Reference](#environment-variable-reference)
- [Troubleshooting](#troubleshooting)

---

## Requirements

| Requirement | Version |
| ----------- | ------- |
| PHP         | >= 8.3  |
| Laravel     | >= 11.0 |

---

## Installation

**1. Install via Composer:**

```bash
composer require syriable/translator
```

**2. Publish the configuration file:**

```bash
php artisan vendor:publish --tag=translator-config
```

**3. Run the migrations:**

```bash
php artisan migrate
```

**4. Publish the language files** (optional — only needed to customise validation messages):

```bash
php artisan vendor:publish --tag=translator-lang
```

---

## Configuration

All configuration lives in `config/translator.php`. Every value can be overridden per-environment via `.env`.

### Minimal `.env` setup

```env
TRANSLATOR_SOURCE_LANGUAGE=en
TRANSLATOR_AI_PROVIDER=claude
ANTHROPIC_API_KEY=sk-ant-api03-...
```

### Full configuration reference

```php
// config/translator.php

return [

    'source_language' => env('TRANSLATOR_SOURCE_LANGUAGE', 'en'),
    'lang_path'       => env('TRANSLATOR_LANG_PATH', null),
    'table_prefix'    => env('TRANSLATOR_TABLE_PREFIX', 'ltu_'),

    'models' => [
        'language'        => \Syriable\Translator\Models\Language::class,
        'group'           => \Syriable\Translator\Models\Group::class,
        'translation_key' => \Syriable\Translator\Models\TranslationKey::class,
        'translation'     => \Syriable\Translator\Models\Translation::class,
        'import_log'      => \Syriable\Translator\Models\ImportLog::class,
        'export_log'      => \Syriable\Translator\Models\ExportLog::class,
    ],

    'import' => [
        'overwrite'         => env('TRANSLATOR_IMPORT_OVERWRITE', true),
        'scan_vendor'       => env('TRANSLATOR_SCAN_VENDOR', true),
        'detect_parameters' => env('TRANSLATOR_DETECT_PARAMETERS', true),
        'detect_html'       => env('TRANSLATOR_DETECT_HTML', true),
        'detect_plural'     => env('TRANSLATOR_DETECT_PLURAL', true),
        'exclude_files'     => [],
        'chunk_size'        => env('TRANSLATOR_CHUNK_SIZE', 500),
    ],

    'export' => [
        'sort_keys'        => env('TRANSLATOR_EXPORT_SORT_KEYS', true),
        'require_approval' => env('TRANSLATOR_EXPORT_REQUIRE_APPROVAL', false),
    ],

    // Controls which source files translator:scan walks.
    'scanner' => [
        'paths'        => [app_path(), resource_path('views')],
        'ignore_paths' => ['vendor', 'node_modules', 'storage', '.git'],
        'extensions'   => ['php', 'blade.php', 'js', 'ts', 'vue'],
    ],

    'cache' => [
        'enabled' => env('TRANSLATOR_CACHE_ENABLED', true),
        'store'   => env('TRANSLATOR_CACHE_STORE', null),
        'ttl'     => env('TRANSLATOR_CACHE_TTL', 3600),
        'prefix'  => env('TRANSLATOR_CACHE_PREFIX', 'syriable_translator'),
    ],

    'events' => [
        'import_completed'         => env('TRANSLATOR_EVENT_IMPORT_COMPLETED', true),
        'export_completed'         => env('TRANSLATOR_EVENT_EXPORT_COMPLETED', true),
        'ai_translation_completed' => env('TRANSLATOR_EVENT_AI_COMPLETED', true),
    ],

    // Days to retain import, export, and AI translation logs.
    // Set to 0 to disable automatic pruning entirely.
    'log_retention_days' => env('TRANSLATOR_LOG_RETENTION_DAYS', 90),

    'ai' => [
        'default_provider' => env('TRANSLATOR_AI_PROVIDER', 'claude'),
        'queue'            => env('TRANSLATOR_AI_QUEUE', 'default'),
        'batch_size'       => env('TRANSLATOR_AI_BATCH_SIZE', 50),

        // Inject reviewed translations as context into the AI system prompt.
        // Only Reviewed-status translations are used — never unreviewed output.
        'translation_memory' => [
            'enabled' => env('TRANSLATOR_AI_MEMORY_ENABLED', true),
            'limit'   => env('TRANSLATOR_AI_MEMORY_LIMIT', 20),
        ],

        'cache' => [
            'enabled' => env('TRANSLATOR_AI_CACHE_ENABLED', true),
            'ttl'     => env('TRANSLATOR_AI_CACHE_TTL', 86400),
            'prefix'  => env('TRANSLATOR_AI_CACHE_PREFIX', 'translator_ai'),
        ],

        'token_estimation' => [
            'default_ratio'            => 4.0,
            'dense_script_ratio'       => 2.0,
            'default_expansion_factor' => 1.2,
        ],

        'providers' => [
            'claude' => [
                'api_key'                   => env('ANTHROPIC_API_KEY'),
                'model'                     => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
                'max_tokens'                => env('ANTHROPIC_MAX_TOKENS', 4096),
                'timeout_seconds'           => env('ANTHROPIC_TIMEOUT', 120),
                'max_retries'               => env('ANTHROPIC_MAX_RETRIES', 3),
                'input_cost_per_1k_tokens'  => 0.003,
                'output_cost_per_1k_tokens' => 0.015,
            ],
        ],
    ],
];
```

---

## Database Structure

The package creates seven tables, all prefixed with `ltu_` by default.

| Table                     | Purpose                                               |
| ------------------------- | ----------------------------------------------------- |
| `ltu_languages`           | Supported locales with metadata (RTL, active, source) |
| `ltu_groups`              | Translation file groups (e.g. `auth`, `validation`)   |
| `ltu_translation_keys`    | Individual translatable keys with metadata            |
| `ltu_translations`        | One row per key × language — the actual values        |
| `ltu_import_logs`         | Audit trail of every import run                       |
| `ltu_export_logs`         | Audit trail of every export run                       |
| `ltu_ai_translation_logs` | Audit trail of every AI translation run with costs    |

---

## Core Concepts

### Language

A `Language` record represents one locale (e.g. `en`, `ar`, `pt-BR`). One language is marked `is_source = true` — this is the reference language all others are translated from. Languages are created automatically during import using metadata from the built-in `LanguageDataProvider` (names, native names, RTL flag for 100+ locales).

### Group

A `Group` maps to a single translation file on disk. PHP files produce named groups (e.g. `auth`, `validation`). JSON files are consolidated under the reserved `_json` group. Vendor packages carry a non-null namespace (e.g. `spatie::permissions`).

### TranslationKey

A `TranslationKey` is a single dot-notation key within a group (e.g. `auth.failed`). It stores metadata detected at import time: parameter tokens, HTML presence, and plural structure.

### Translation

A `Translation` is the value for one `TranslationKey` in one `Language`. The `status` field tracks lifecycle: `untranslated` → `translated` → `reviewed`.

### TranslationStatus

```php
use Syriable\Translator\Enums\TranslationStatus;

TranslationStatus::Untranslated  // No value yet
TranslationStatus::Translated    // Value provided (by import or AI)
TranslationStatus::Reviewed      // Value reviewed and approved
```

---

## Artisan Commands

### `translator:import`

Reads all PHP and JSON translation files from the configured lang directory and persists their contents to the database.

```bash
php artisan translator:import
php artisan translator:import --fresh
php artisan translator:import --no-overwrite
```

| Option           | Description                                      |
| ---------------- | ------------------------------------------------ |
| `--fresh`        | Purge all existing translations before importing |
| `--no-overwrite` | Skip updating existing translation values        |

---

### `translator:export`

Reads `Translation` records from the database and writes them back to disk as PHP group files and JSON locale files.

```bash
php artisan translator:export
php artisan translator:export --locale=ar
php artisan translator:export --group=auth
php artisan translator:export --locale=ar --group=auth
php artisan translator:export --dry-run
php artisan translator:export --locale=fr --dry-run
```

| Option      | Description                                                |
| ----------- | ---------------------------------------------------------- |
| `--locale=` | Export only this locale code (e.g. `ar`, `fr`)             |
| `--group=`  | Export only this group name (e.g. `auth`, `validation`)    |
| `--dry-run` | Preview which files would be written without touching disk |

**Dry-run mode** lists every file path that would be created or overwritten, along with locale and key counts. No files are written, no `ExportLog` is recorded, and no `ExportCompleted` event is dispatched. Re-run without `--dry-run` to execute the export.

**Output file locations:**

| Type                  | Path                                           |
| --------------------- | ---------------------------------------------- |
| PHP application group | `lang/{locale}/{group}.php`                    |
| JSON locale file      | `lang/{locale}.json`                           |
| PHP vendor group      | `lang/vendor/{namespace}/{locale}/{group}.php` |

---

### `translator:scan`

Scans your application source files for translation key calls (`__()`, `trans()`, `@lang()`, etc.) and compares them against the `TranslationKey` records in the database.

- **Missing keys** — keys called in code that have no database record. These silently fall back to the key string at runtime.
- **Orphaned keys** — database keys not referenced anywhere in the scanned source files.

> Vendor-namespaced keys (e.g. `spatie::permissions`) are always excluded from the orphan report.

```bash
php artisan translator:scan
php artisan translator:scan --missing-only
php artisan translator:scan --orphans-only
php artisan translator:scan --fail-on-missing
php artisan translator:scan --sync
php artisan translator:scan --purge-orphans
```

| Option              | Description                                                             |
| ------------------- | ----------------------------------------------------------------------- |
| `--missing-only`    | Only report keys in code but absent from the database                   |
| `--orphans-only`    | Only report keys in the database but absent from code                   |
| `--fail-on-missing` | Exit with code 1 when missing keys are found (CI gate)                  |
| `--sync`            | Insert missing keys into the database as empty `TranslationKey` records |
| `--purge-orphans`   | Delete orphaned `TranslationKey` records (requires confirmation)        |

**Supported call forms:** `__()`, `trans()`, `trans_choice()`, `@lang()`, `Lang::get()`, `Lang::choice()`, `$t()` (Vue), `i18n.t()` across PHP, Blade, JS, TS, and Vue files.

**Using as a CI gate:**

```yaml
- name: Check for missing translation keys
  run: php artisan translator:scan --fail-on-missing
```

**Configuring the scanner:**

```php
'scanner' => [
    'paths'        => [app_path(), resource_path('views'), resource_path('js')],
    'ignore_paths' => ['vendor', 'node_modules', 'storage', '.git'],
    'extensions'   => ['php', 'blade.php', 'js', 'ts', 'vue'],
],
```

> Keys built dynamically at runtime (e.g. `__("status.$state")`) cannot be found by the static scanner and will appear orphaned even when in active use. Review the orphan list carefully before using `--purge-orphans`.

---

### `translator:ai-translate`

Translates all untranslated keys for a target language using an AI provider. Always shows a cost estimate before making any API call and requires explicit confirmation.

```bash
php artisan translator:ai-translate --target=ar
php artisan translator:ai-translate --target=ar --group=auth
php artisan translator:ai-translate --target=ar --provider=claude
php artisan translator:ai-translate --target=ar --force --no-interaction
php artisan translator:ai-translate --target=ar --queue
php artisan translator:ai-translate --target=ar --fresh-cache
```

| Option          | Description                                                      |
| --------------- | ---------------------------------------------------------------- |
| `--source=`     | Source language code. Defaults to `translator.source_language`   |
| `--target=`     | Target language code to translate into (required)                |
| `--group=`      | Translate only keys in this group                                |
| `--provider=`   | AI provider to use. Defaults to `translator.ai.default_provider` |
| `--queue`       | Dispatch jobs to the queue instead of running synchronously      |
| `--force`       | Skip the cost confirmation prompt                                |
| `--fresh-cache` | Ignore cached translations and force fresh API calls             |

---

### `translator:ai-stats`

Displays a usage dashboard for AI translation runs — cost breakdown, token consumption, and success rates by provider and target language.

```bash
php artisan translator:ai-stats
php artisan translator:ai-stats --provider=claude
php artisan translator:ai-stats --days=7
```

| Option        | Description                             |
| ------------- | --------------------------------------- |
| `--provider=` | Filter stats to a single provider       |
| `--days=`     | Number of days to include (default: 30) |

---

### `translator:queue-check`

Verifies your queue configuration is correct for AI translation jobs.

```bash
php artisan translator:queue-check
php artisan translator:queue-check --dispatch-test
```

Checks performed: queue driver, jobs table existence, job serialization, optional test dispatch.

---

### `translator:prune-logs`

Deletes `ImportLog`, `ExportLog`, and `AITranslationLog` records older than the configured retention window.

The package **automatically registers this command with the Laravel scheduler** (`weekly`) when `log_retention_days > 0`. No manual scheduling required.

```bash
php artisan translator:prune-logs
php artisan translator:prune-logs --days=30
php artisan translator:prune-logs --dry-run
php artisan translator:prune-logs --days=30 --dry-run
```

| Option      | Description                                                    |
| ----------- | -------------------------------------------------------------- |
| `--days=`   | Retention window in days (overrides `log_retention_days`)      |
| `--dry-run` | Show record counts that would be deleted without deleting them |

To disable automatic pruning entirely, set `TRANSLATOR_LOG_RETENTION_DAYS=0`.

---

## AI Translation System

### How it works

```
1. estimate()     ← No API call. Calculates tokens and cost from character counts.
2. User confirms  ← Shows breakdown table, requires explicit Yes/No.
3. translate()    ← Checks cache → API call for uncached keys → persist → log → event.
```

### Cost estimation

Before any API call is made, the `TokenEstimator` approximates input tokens, output tokens, and estimated cost based on character counts and provider-specific pricing rates. Actual usage reported after the API call may differ by ±15%.

Estimation uses locale-aware characters-per-token ratios:

- Latin scripts (`en`, `fr`, `de`, etc.): ~4 chars/token
- Dense scripts (`ar`, `zh`, `ja`, `hi`, etc.): ~2 chars/token

### Translation memory

When `translator.ai.translation_memory.enabled` is `true` (default), the AI system prompt is enriched with up to `limit` previously reviewed translations for the target language. This improves terminology consistency across batches and across separate translation runs.

Only `Reviewed`-status translations are used as memory sources — never `Translated` — to prevent unreviewed AI output from propagating into future prompts.

```env
TRANSLATOR_AI_MEMORY_ENABLED=true
TRANSLATOR_AI_MEMORY_LIMIT=20
```

To get the most out of translation memory, review and approve good AI translations by setting their status to `Reviewed`. On subsequent runs those translations will anchor the AI's terminology choices.

### Plural form awareness

The AI system prompt automatically includes the exact CLDR plural form count required by the target language. This prevents the common AI failure of producing two-variant output for a language that requires three, four, five, or six forms.

| Language | Forms | Categories                       |
| -------- | ----- | -------------------------------- |
| Arabic   | 6     | zero, one, two, few, many, other |
| Welsh    | 6     | zero, one, two, few, many, other |
| Irish    | 5     | one, two, few, many, other       |
| Polish   | 4     | one, few, many, other            |
| Russian  | 3     | one, few, other                  |
| English  | 2     | one, other                       |
| Japanese | 1     | (no pipes — all quantities same) |

Access plural form data directly:

```php
use Syriable\Translator\Support\PluralFormProvider;

PluralFormProvider::formCount('ar');           // 6
PluralFormProvider::formNames('ru');           // ['one', 'few', 'other']
PluralFormProvider::isSingular('ja');          // true
PluralFormProvider::describe('ar', 'Arabic');  // "Arabic requires 6 plural forms: zero | one | ..."
```

### Supported providers

| Provider           | Driver class    | `.env` key          |
| ------------------ | --------------- | ------------------- |
| Claude (Anthropic) | `ClaudeDriver`  | `ANTHROPIC_API_KEY` |
| ChatGPT (OpenAI)   | `ChatGptDriver` | `OPENAI_API_KEY`    |

### Adding a new AI provider

**Step 1 — Create a driver** implementing `TranslationProviderInterface`:

```php
namespace App\AI\Drivers;

use Syriable\Translator\AI\Contracts\TranslationProviderInterface;

final class GeminiDriver implements TranslationProviderInterface
{
    public function estimate(TranslationRequest $request): TranslationEstimate { /* ... */ }
    public function translate(TranslationRequest $request): TranslationResponse { /* ... */ }
    public function providerName(): string { return 'gemini'; }
    public function isAvailable(): bool { return filled(config('translator.ai.providers.gemini.api_key')); }
}
```

**Step 2 — Register it:**

```php
use Syriable\Translator\AI\TranslationProviderManager;

$this->app->afterResolving(TranslationProviderManager::class, function ($manager): void {
    $manager->extend('gemini', fn () => app(GeminiDriver::class));
});
```

**Step 3 — Add pricing to config** and use it:

```bash
php artisan translator:ai-translate --target=ar --provider=gemini
```

### Switching the default provider

```env
TRANSLATOR_AI_PROVIDER=claude
TRANSLATOR_AI_PROVIDER=chatgpt
TRANSLATOR_AI_PROVIDER=gemini
```

### Using the queue for large batches

```bash
QUEUE_CONNECTION=database
php artisan queue:table && php artisan migrate
php artisan config:clear
php artisan translator:queue-check
php artisan translator:ai-translate --target=ar --queue
php artisan queue:work --queue=default --tries=3
```

### Translation cache

The AI cache stores translated values after each API call. The cache key includes an MD5 of the source value, so the cache is automatically invalidated when a source string changes.

```env
TRANSLATOR_AI_CACHE_ENABLED=true
TRANSLATOR_AI_CACHE_TTL=86400
TRANSLATOR_AI_CACHE_STORE=redis
```

Bypass for one run:

```bash
php artisan translator:ai-translate --target=ar --fresh-cache
```

---

## Facade

The `Translator` facade provides static access to the AI translation service:

```php
use Syriable\Translator\Facades\Translator;
use Syriable\Translator\DTOs\AI\TranslationRequest;

$request = new TranslationRequest(
    sourceLanguage: 'en',
    targetLanguage: 'ar',
    keys: ['auth.failed' => 'These credentials do not match.'],
    groupName: 'auth',
);

// Always estimate first — no API call is made.
$estimate = Translator::estimate($request);

// Execute after confirming the cost.
$response = Translator::translate($request, $language);
```

The facade resolves from the container via `AITranslationServiceContract`. The alias `Translator` is registered automatically — no additional configuration is needed.

---

## Public Contracts

Three thin interfaces define the stable public API surface. Companion packages should type-hint against these contracts rather than the concrete classes:

```php
use Syriable\Translator\Contracts\TranslationImporterContract;
use Syriable\Translator\Contracts\TranslationExporterContract;
use Syriable\Translator\Contracts\AITranslationServiceContract;

public function __construct(
    private readonly TranslationImporterContract  $importer,
    private readonly TranslationExporterContract  $exporter,
    private readonly AITranslationServiceContract $ai,
) {}
```

All three are bound in the service provider. To substitute a custom implementation:

```php
$this->app->bind(TranslationImporterContract::class, MyCustomImporter::class);
```

---

## Models

### `Language`

```php
Language::query()->active()->get();
Language::query()->source()->first();
Language::query()->rtl()->get();

$language->isRtl();
$language->isSource();
```

### `Group`

```php
Group::query()->application()->get();
Group::query()->vendor()->get();
Group::query()->withFormat('php')->get();

$group->isVendor();
$group->isJson();
$group->qualifiedName();
```

### `TranslationKey`

```php
TranslationKey::query()->withParameters()->get();
TranslationKey::query()->plural()->get();
TranslationKey::query()->html()->get();

$key->hasParameters();
$key->parameterNames();
```

### `Translation`

```php
Translation::query()->untranslated()->get();
Translation::query()->translated()->get();
Translation::query()->reviewed()->get();
Translation::query()->withStatus(TranslationStatus::Reviewed)->get();
Translation::query()->source()->get();
Translation::query()->forLocale('ar')->get();

$translation->hasValue();
$translation->isComplete();
```

### `AITranslationLog`

```php
AITranslationLog::query()->forProvider('claude')->get();
AITranslationLog::query()->forLanguagePair('en', 'ar')->get();
AITranslationLog::query()->withFailures()->get();

$log->totalTokensUsed();
$log->formattedActualCost();
$log->costVariancePercent();
$log->successRate();
$log->hadFailures();
```

---

## Events

### `ImportCompleted`

Dispatched after every successful import run. Carries the `ImportLog` record.

```php
use Syriable\Translator\Events\ImportCompleted;

Event::listen(ImportCompleted::class, function (ImportCompleted $event): void {
    logger()->info('Import finished', ['keys' => $event->log->key_count]);
});
```

Disable: `'events' => ['import_completed' => false]`

### `ExportCompleted`

Dispatched after every successful export run. Carries the `ExportLog` record.

> **Not dispatched in dry-run mode.**

```php
use Syriable\Translator\Events\ExportCompleted;

Event::listen(ExportCompleted::class, function (ExportCompleted $event): void {
    logger()->info('Export finished', ['files' => $event->log->file_count]);
});
```

Disable: `'events' => ['export_completed' => false]`

### `AITranslationCompleted`

Dispatched after every AI translation execution — both synchronous API calls and full cache hits, and individual queue job completions. Carries the persisted `AITranslationLog` record.

Use this event to send notifications, invalidate caches, or trigger downstream workflows without polling.

```php
use Syriable\Translator\Events\AITranslationCompleted;

Event::listen(AITranslationCompleted::class, function (AITranslationCompleted $event): void {
    $log = $event->log;

    logger()->info('AI translation finished', [
        'target'     => $log->target_language,
        'translated' => $log->translated_count,
        'cost'       => $log->formattedActualCost(),
    ]);
});
```

Disable: `'events' => ['ai_translation_completed' => false]`

---

## Validation Rules

### `TranslationParametersRule`

Fails when a submitted translation value is missing one or more interpolation parameters.

```php
use Syriable\Translator\Rules\TranslationParametersRule;

$request->validate([
    'value' => ['nullable', 'string', new TranslationParametersRule($translationKey)],
]);

// Check programmatically:
$missing = TranslationParametersRule::missingParametersFor($translationKey, $submittedValue);
```

### `TranslationPluralRule`

Fails when a plural translation value does not contain the same number of pipe-delimited variants as the source language value.

```php
use Syriable\Translator\Rules\TranslationPluralRule;

$request->validate([
    'value' => ['nullable', 'string', new TranslationPluralRule($translationKey)],
]);
```

---

## Extending the Package

### Custom models

Override any model with your own subclass without modifying package code:

```php
namespace App\Models;

use Syriable\Translator\Models\Translation as BaseTranslation;

class Translation extends BaseTranslation
{
    protected static function booted(): void
    {
        static::updated(function (self $translation): void {
            cache()->forget("translation:{$translation->language_id}");
        });
    }
}
```

Register in `config/translator.php`:

```php
'models' => [
    'translation' => App\Models\Translation::class,
],
```

All package services — the importer, exporter, replicator, AI translator, and scanner — resolve and use your custom class automatically.

### Custom table prefix

Change the prefix before running migrations for the first time:

```env
TRANSLATOR_TABLE_PREFIX=mytrans_
```

> **Do not change the prefix after migrations have run.**

---

## Environment Variable Reference

| Variable                             | Default                     | Description                                |
| ------------------------------------ | --------------------------- | ------------------------------------------ |
| `TRANSLATOR_SOURCE_LANGUAGE`         | `en`                        | BCP 47 code of the source language         |
| `TRANSLATOR_LANG_PATH`               | `null`                      | Override the lang directory path           |
| `TRANSLATOR_TABLE_PREFIX`            | `ltu_`                      | Database table prefix                      |
| `TRANSLATOR_IMPORT_OVERWRITE`        | `true`                      | Overwrite existing values on import        |
| `TRANSLATOR_SCAN_VENDOR`             | `true`                      | Scan `lang/vendor` during import           |
| `TRANSLATOR_DETECT_PARAMETERS`       | `true`                      | Detect `:param` and `{param}` tokens       |
| `TRANSLATOR_DETECT_HTML`             | `true`                      | Detect inline HTML in source strings       |
| `TRANSLATOR_DETECT_PLURAL`           | `true`                      | Detect pipe plural syntax                  |
| `TRANSLATOR_CHUNK_SIZE`              | `500`                       | Keys per DB chunk during replication       |
| `TRANSLATOR_EXPORT_SORT_KEYS`        | `true`                      | Sort keys alphabetically on export         |
| `TRANSLATOR_EXPORT_REQUIRE_APPROVAL` | `false`                     | Only export Reviewed translations          |
| `TRANSLATOR_CACHE_ENABLED`           | `true`                      | Enable translation output caching          |
| `TRANSLATOR_CACHE_TTL`               | `3600`                      | Cache TTL in seconds                       |
| `TRANSLATOR_LOG_RETENTION_DAYS`      | `90`                        | Days to retain logs (0 = disabled)         |
| `TRANSLATOR_EVENT_IMPORT_COMPLETED`  | `true`                      | Dispatch `ImportCompleted` event           |
| `TRANSLATOR_EVENT_EXPORT_COMPLETED`  | `true`                      | Dispatch `ExportCompleted` event           |
| `TRANSLATOR_EVENT_AI_COMPLETED`      | `true`                      | Dispatch `AITranslationCompleted` event    |
| `TRANSLATOR_AI_PROVIDER`             | `claude`                    | Default AI provider                        |
| `TRANSLATOR_AI_QUEUE`                | `default`                   | Queue name for AI translation jobs         |
| `TRANSLATOR_AI_BATCH_SIZE`           | `50`                        | Max keys per API request                   |
| `TRANSLATOR_AI_CACHE_ENABLED`        | `true`                      | Cache AI translation results               |
| `TRANSLATOR_AI_CACHE_TTL`            | `86400`                     | AI cache TTL in seconds (0 = forever)      |
| `TRANSLATOR_AI_MEMORY_ENABLED`       | `true`                      | Inject reviewed translations as AI context |
| `TRANSLATOR_AI_MEMORY_LIMIT`         | `20`                        | Max reviewed translations to inject        |
| `ANTHROPIC_API_KEY`                  | —                           | Anthropic Claude API key                   |
| `ANTHROPIC_MODEL`                    | `claude-haiku-4-5-20251001` | Claude model to use                        |
| `ANTHROPIC_MAX_TOKENS`               | `4096`                      | Max output tokens per request              |
| `ANTHROPIC_TIMEOUT`                  | `120`                       | Request timeout in seconds                 |
| `ANTHROPIC_MAX_RETRIES`              | `3`                         | Retries on transient failures              |
| `OPENAI_API_KEY`                     | —                           | OpenAI API key (ChatGPT driver)            |

---

## Troubleshooting

### `$0.0000` actual cost — nothing saved to database

The AI cache was hit from a previous run. To force a fresh call:

```bash
php artisan translator:ai-translate --target=ar --fresh-cache
```

### Jobs dispatched but nothing in the `jobs` table

Your `QUEUE_CONNECTION` is `sync`. Set it to `database`:

```env
QUEUE_CONNECTION=database
```

```bash
php artisan queue:table && php artisan migrate
php artisan config:clear
php artisan translator:queue-check
```

### `php artisan queue:work` shows no jobs

```bash
php artisan translator:queue-check --dispatch-test
```

Common causes: config cache not cleared, worker listening on wrong queue, `jobs` table in a different database connection.

### "No untranslated keys found" but keys exist in the database

Keys already have a `translated` or `reviewed` status. Reset them via Tinker:

```bash
php artisan tinker --execute="
    \Syriable\Translator\Models\Translation::query()
        ->whereHas('language', fn(\$q) => \$q->where('code', 'ar'))
        ->update(['status' => 'untranslated', 'value' => null]);
"
```

### `translator:scan` reports many orphaned keys for keys I know are in use

The scanner performs **static analysis** only — keys built at runtime are invisible to it:

```php
// These will NOT be detected:
__("status.$state")
__('notifications.' . $type)
$key = 'auth.failed'; __($key);
```

Review the orphan list manually before using `--purge-orphans`.

### AI translations are inconsistent across batches

Enable translation memory so reviewed translations are injected into the prompt as consistency anchors:

```env
TRANSLATOR_AI_MEMORY_ENABLED=true
TRANSLATOR_AI_MEMORY_LIMIT=20
```

Mark good translations as `Reviewed`. On subsequent runs they will be included in the system prompt.

### Provider authentication error

```env
ANTHROPIC_API_KEY=sk-ant-api03-...
```

```bash
php artisan config:clear
```

### Rate limit errors (`429`)

Reduce the batch size:

```env
TRANSLATOR_AI_BATCH_SIZE=20
```

---

## License

MIT
