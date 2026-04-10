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
    - [translator:ai-translate](#translatorai-translate)
    - [translator:ai-stats](#translatorai-stats)
    - [translator:queue-check](#translatorqueue-check)
- [AI Translation System](#ai-translation-system)
    - [How it works](#how-it-works)
    - [Cost estimation](#cost-estimation)
    - [Supported providers](#supported-providers)
    - [Adding a new AI provider](#adding-a-new-ai-provider)
    - [Switching the default provider](#switching-the-default-provider)
    - [Using the queue for large batches](#using-the-queue-for-large-batches)
    - [Translation cache](#translation-cache)
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
# Source language for your application
TRANSLATOR_SOURCE_LANGUAGE=en

# AI translation provider (claude or chatgpt)
TRANSLATOR_AI_PROVIDER=claude

# Anthropic Claude API key
ANTHROPIC_API_KEY=sk-ant-api03-...
```

### Full configuration reference

```php
// config/translator.php

return [

    // The locale code of the reference language for all translations.
    'source_language' => env('TRANSLATOR_SOURCE_LANGUAGE', 'en'),

    // Absolute path to the lang directory. Defaults to lang_path().
    'lang_path' => env('TRANSLATOR_LANG_PATH', null),

    // Prefix applied to all package database tables.
    'table_prefix' => env('TRANSLATOR_TABLE_PREFIX', 'ltu_'),

    // Override individual models with your own subclasses.
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
        'exclude_files'     => [], // e.g. ['validation.php', 'passwords.php']
        'chunk_size'        => env('TRANSLATOR_CHUNK_SIZE', 500),
    ],

    'export' => [
        'sort_keys'        => env('TRANSLATOR_EXPORT_SORT_KEYS', true),
        'require_approval' => env('TRANSLATOR_EXPORT_REQUIRE_APPROVAL', false),
    ],

    'cache' => [
        'enabled' => env('TRANSLATOR_CACHE_ENABLED', true),
        'store'   => env('TRANSLATOR_CACHE_STORE', null),
        'ttl'     => env('TRANSLATOR_CACHE_TTL', 3600),
        'prefix'  => env('TRANSLATOR_CACHE_PREFIX', 'syriable_translator'),
    ],

    'events' => [
        'import_completed' => env('TRANSLATOR_EVENT_IMPORT_COMPLETED', true),
        'export_completed' => env('TRANSLATOR_EVENT_EXPORT_COMPLETED', true),
    ],

    'log_retention_days' => env('TRANSLATOR_LOG_RETENTION_DAYS', 90),

    'ai' => [
        'default_provider' => env('TRANSLATOR_AI_PROVIDER', 'claude'),
        'queue'            => env('TRANSLATOR_AI_QUEUE', 'default'),
        'batch_size'       => env('TRANSLATOR_AI_BATCH_SIZE', 50),

        'cache' => [
            'enabled' => env('TRANSLATOR_AI_CACHE_ENABLED', true),
            'ttl'     => env('TRANSLATOR_AI_CACHE_TTL', 86400), // seconds
            'prefix'  => env('TRANSLATOR_AI_CACHE_PREFIX', 'translator_ai'),
        ],

        'token_estimation' => [
            'default_ratio'            => 4.0,  // Latin scripts
            'dense_script_ratio'       => 2.0,  // Arabic, CJK, Indic
            'default_expansion_factor' => 1.2,  // Expected output size vs input
        ],

        'providers' => [
            'claude' => [
                'api_key'                   => env('ANTHROPIC_API_KEY'),
                'model'                     => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
                'max_tokens'                => env('ANTHROPIC_MAX_TOKENS', 4096),
                'timeout_seconds'           => env('ANTHROPIC_TIMEOUT', 120),
                'max_retries'               => env('ANTHROPIC_MAX_RETRIES', 3),
                'input_cost_per_1k_tokens'  => 0.003,
                'output_cost_per_1k_tokens' => 0.015,
            ],
            // 'chatgpt' => [ ... ],
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

A `TranslationKey` is a single dot-notation key within a group (e.g. `auth.failed`, `Welcome :name`). It stores metadata detected at import time:

- `parameters` — interpolation tokens found in the source string (`:name`, `{count}`)
- `is_html` — whether the source string contains inline HTML
- `is_plural` — whether the source string uses Laravel's pipe plural syntax

### Translation

A `Translation` is the value for one `TranslationKey` in one `Language`. Each key gets one `Translation` row per active language, managed automatically by `TranslationKeyReplicator`. The `status` field tracks lifecycle: `untranslated` → `translated` → `reviewed`.

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
# Import all translation files
php artisan translator:import

# Purge all existing data before importing (fresh start)
php artisan translator:import --fresh

# Import but never overwrite existing translation values
php artisan translator:import --no-overwrite
```

**What it does:**

- Discovers PHP group files (`lang/en/auth.php`, `lang/en/validation.php`, etc.)
- Discovers JSON locale files (`lang/en.json`, `lang/ar.json`)
- Discovers vendor-namespaced files (`lang/vendor/spatie/en/permissions.php`)
- Creates or updates `Language`, `Group`, `TranslationKey`, and `Translation` records
- Detects and stores parameter tokens, HTML flags, and plural flags on each key
- Replicates all keys to every active language (creates `untranslated` rows)
- Dispatches an `ImportCompleted` event on success
- Writes an `ImportLog` record with full metrics

**Example output:**

```
Import completed in 1.4s
┌──────────────────────┬───────┐
│ Metric               │ Value │
├──────────────────────┼───────┤
│ Locales processed    │ 1     │
│ Total keys           │ 247   │
│ New keys inserted    │ 247   │
│ Existing updated     │ 0     │
│ Duration             │ 1.4s  │
└──────────────────────┴───────┘
```

---

### `translator:export`

Reads `Translation` records from the database and writes them back to disk as PHP group files and JSON locale files, preserving the original Laravel file structure.

```bash
# Export all active languages
php artisan translator:export

# Export a single locale
php artisan translator:export --locale=ar

# Export a single group across all locales
php artisan translator:export --group=auth

# Export a single group for a single locale
php artisan translator:export --locale=ar --group=auth
```

**Options:**

| Option      | Description                                             |
| ----------- | ------------------------------------------------------- |
| `--locale=` | Export only this locale code (e.g. `ar`, `fr`)          |
| `--group=`  | Export only this group name (e.g. `auth`, `validation`) |

**Output file locations:**

| Type                  | Path                                           |
| --------------------- | ---------------------------------------------- |
| PHP application group | `lang/{locale}/{group}.php`                    |
| JSON locale file      | `lang/{locale}.json`                           |
| PHP vendor group      | `lang/vendor/{namespace}/{locale}/{group}.php` |

---

### `translator:ai-translate`

Translates all untranslated keys for a target language using an AI provider.

**Enforces the "no execution without cost preview" rule** — always shows the estimated token usage and cost before making any API call, and requires explicit confirmation.

```bash
# Translate all untranslated keys into Arabic (interactive)
php artisan translator:ai-translate --target=ar

# Translate only the auth group
php artisan translator:ai-translate --target=ar --group=auth

# Use a specific provider
php artisan translator:ai-translate --target=ar --provider=claude

# Skip the confirmation prompt (CI/scripts)
php artisan translator:ai-translate --target=ar --force --no-interaction

# Dispatch to the queue instead of running synchronously
php artisan translator:ai-translate --target=ar --queue

# Bypass the cache and force fresh API calls for all keys
php artisan translator:ai-translate --target=ar --fresh-cache
```

**Options:**

| Option          | Description                                                      |
| --------------- | ---------------------------------------------------------------- |
| `--source=`     | Source language code. Defaults to `translator.source_language`   |
| `--target=`     | Target language code to translate into (required)                |
| `--group=`      | Translate only keys in this group                                |
| `--provider=`   | AI provider to use. Defaults to `translator.ai.default_provider` |
| `--queue`       | Dispatch jobs to the queue instead of running synchronously      |
| `--force`       | Skip the cost confirmation prompt                                |
| `--fresh-cache` | Ignore cached translations and force fresh API calls             |

**Example flow:**

```
⚠️  Review this cost estimate before proceeding:
┌──────────────────────┬──────────────────────┐
│ Metric               │ Value                │
├──────────────────────┼──────────────────────┤
│ Provider             │ Claude               │
│ Model                │ claude-sonnet-4-6    │
│ Keys to translate    │ 247                  │
│ Source characters    │ 8,432                │
│ Input tokens         │ 3,210                │
│ Output tokens        │ 1,840                │
│ Total tokens         │ 5,050                │
│ Estimated cost       │ $0.0372              │
└──────────────────────┴──────────────────────┘

 Proceed with translation? (estimated cost: $0.0372) › Yes

 ⠸ Translating batch 1/5...

✅ Translation complete
┌─────────────────┬───────┐
│ Keys translated │ 247   │
│   From API      │ 200   │
│   From cache    │ 47    │
│ Keys failed     │ 0     │
│ Actual cost     │$0.031 │
└─────────────────┴───────┘
```

---

### `translator:ai-stats`

Displays a usage dashboard for AI translation runs — cost breakdown, token consumption, and success rates by provider and target language.

```bash
# Stats for the last 30 days (default)
php artisan translator:ai-stats

# Filter by provider
php artisan translator:ai-stats --provider=claude

# Custom time window
php artisan translator:ai-stats --days=7
```

**Options:**

| Option        | Description                             |
| ------------- | --------------------------------------- |
| `--provider=` | Filter stats to a single provider       |
| `--days=`     | Number of days to include (default: 30) |

---

### `translator:queue-check`

Verifies your queue configuration is correct for AI translation jobs before using `--queue`. Checks the queue driver, jobs table existence, job serialization, and optionally dispatches a test job.

```bash
# Run all diagnostic checks
php artisan translator:queue-check

# Also dispatch a test job to confirm end-to-end storage
php artisan translator:queue-check --dispatch-test
```

**Checks performed:**

1. **Queue driver** — warns if `QUEUE_CONNECTION=sync` (jobs never stored with sync)
2. **Jobs table** — verifies `jobs` table exists when using the `database` driver
3. **Job serialization** — serializes and deserializes a real job to confirm no errors
4. **Test dispatch** _(optional)_ — dispatches a real job and confirms it appears in the database

---

## AI Translation System

### How it works

The AI translation pipeline follows a strict sequence that ensures you always see the cost before paying it:

```
1. estimate()     ← No API call. Calculates tokens and cost from character counts.
2. User confirms  ← Shows breakdown table, requires explicit Yes/No.
3. translate()    ← Checks cache → API call for uncached keys → persist → log.
```

This sequence is enforced architecturally — `AITranslationService::estimate()` and `AITranslationService::translate()` are separate methods that must be called in order.

### Cost estimation

Before any API call is made, the `TokenEstimator` approximates:

- **Input tokens** — system prompt length + request framing + all source string values
- **Output tokens** — expected translated output size with a per-locale expansion factor
- **Estimated cost** — tokens × provider-specific rates from config

Estimation uses locale-aware characters-per-token ratios:

- Latin scripts (`en`, `fr`, `de`, etc.): ~4 chars/token
- Dense scripts (`ar`, `zh`, `ja`, `hi`, etc.): ~2 chars/token

Actual usage reported after the API call may differ by ±15%.

### Supported providers

| Provider           | Driver class    | `.env` key          |
| ------------------ | --------------- | ------------------- |
| Claude (Anthropic) | `ClaudeDriver`  | `ANTHROPIC_API_KEY` |
| ChatGPT (OpenAI)   | `ChatGptDriver` | `OPENAI_API_KEY`    |

### Adding a new AI provider

**Step 1 — Create a driver class** that implements `TranslationProviderInterface`:

```php
<?php

namespace App\AI\Drivers;

use Syriable\Translator\AI\Contracts\TranslationProviderInterface;
use Syriable\Translator\AI\Estimators\TokenEstimator;
use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;
use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;

final class GeminiDriver implements TranslationProviderInterface
{
    public function __construct(
        private readonly TokenEstimator $estimator,
        private readonly TranslationPromptBuilder $promptBuilder,
    ) {}

    public function estimate(TranslationRequest $request): TranslationEstimate
    {
        $systemPrompt = $this->promptBuilder->buildSystemPrompt($request);
        $userMessage  = $this->promptBuilder->buildUserMessage($request);

        $inputTokens  = $this->estimator->estimateInputTokens(
            prompt: $systemPrompt . $userMessage,
            keys: $request->keys,
            sourceLocale: $request->sourceLanguage,
        );
        $outputTokens = $this->estimator->estimateOutputTokens(
            keys: $request->keys,
            targetLocale: $request->targetLanguage,
        );

        return new TranslationEstimate(
            provider: $this->providerName(),
            model: $this->resolveModel(),
            estimatedInputTokens: $inputTokens,
            estimatedOutputTokens: $outputTokens,
            estimatedCostUsd: $this->estimator->estimateCost($this->providerName(), $inputTokens, $outputTokens),
            keyCount: $request->keyCount(),
            sourceCharacters: $request->totalSourceCharacters(),
        );
    }

    public function translate(TranslationRequest $request): TranslationResponse
    {
        // Build prompt, call the Gemini API, parse JSON response.
        // Return a TranslationResponse with the translated values.
        $startTime = microtime(true);

        // ... your HTTP call here ...

        return new TranslationResponse(
            provider: $this->providerName(),
            model: $this->resolveModel(),
            translations: $translatedKeys,   // array<string, string>
            failedKeys: $failedKeys,          // string[]
            inputTokensUsed: $inputTokens,
            outputTokensUsed: $outputTokens,
            actualCostUsd: $actualCost,
            durationMs: (int) ((microtime(true) - $startTime) * 1000),
        );
    }

    public function providerName(): string
    {
        return 'gemini';
    }

    public function isAvailable(): bool
    {
        return filled(config('translator.ai.providers.gemini.api_key'));
    }

    private function resolveModel(): string
    {
        return config('translator.ai.providers.gemini.model', 'gemini-1.5-pro');
    }
}
```

**Step 2 — Register the driver** in a service provider:

```php
use Syriable\Translator\AI\TranslationProviderManager;
use App\AI\Drivers\GeminiDriver;

public function boot(): void
{
    $this->app->afterResolving(TranslationProviderManager::class,
        function (TranslationProviderManager $manager): void {
            $manager->extend('gemini', fn () => new GeminiDriver(
                estimator: app(TokenEstimator::class),
                promptBuilder: app(TranslationPromptBuilder::class),
            ));
        }
    );
}
```

**Step 3 — Add pricing to config:**

```php
// config/translator.php
'providers' => [
    'gemini' => [
        'api_key'                   => env('GEMINI_API_KEY'),
        'model'                     => env('GEMINI_MODEL', 'gemini-1.5-pro'),
        'max_tokens'                => 8192,
        'timeout_seconds'           => 120,
        'max_retries'               => 3,
        'input_cost_per_1k_tokens'  => 0.00125,
        'output_cost_per_1k_tokens' => 0.005,
    ],
],
```

**Step 4 — Use it:**

```bash
php artisan translator:ai-translate --target=ar --provider=gemini
```

---

### Switching the default provider

Change `TRANSLATOR_AI_PROVIDER` in `.env`:

```env
# Use Claude (default)
TRANSLATOR_AI_PROVIDER=claude

# Use ChatGPT
TRANSLATOR_AI_PROVIDER=chatgpt

# Use your custom Gemini driver
TRANSLATOR_AI_PROVIDER=gemini
```

Or specify per-command with `--provider=`:

```bash
php artisan translator:ai-translate --target=ar --provider=chatgpt
```

---

### Switching or updating the AI model

Change the model inside `config/translator.php` or via `.env`:

```env
# Use a different Claude model
ANTHROPIC_MODEL=claude-haiku-4-5-20251001

# Use a different OpenAI model
OPENAI_MODEL=gpt-4-turbo
```

Update pricing to match the new model:

```php
'claude' => [
    'model'                     => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
    'input_cost_per_1k_tokens'  => 0.015,   // Opus is more expensive
    'output_cost_per_1k_tokens' => 0.075,
],
```

> Always verify pricing against the provider's current documentation. The values in config are used only for **pre-execution estimates** shown to the user — actual cost is calculated from the token counts reported by the API after each call.

---

### Using the queue for large batches

For large translation jobs (hundreds of keys), use `--queue` to avoid HTTP timeouts and run translations in the background.

**Setup (one time):**

```bash
# 1. Set queue driver in .env (must not be "sync")
QUEUE_CONNECTION=database

# 2. Create the jobs table
php artisan queue:table
php artisan migrate

# 3. Clear config cache
php artisan config:clear

# 4. Verify everything is correct
php artisan translator:queue-check
```

**Dispatch jobs:**

```bash
php artisan translator:ai-translate --target=ar --queue
```

**Start the worker (in a separate terminal or process manager):**

```bash
php artisan queue:work --queue=default --tries=3
```

**Important notes:**

- Jobs are dispatched but **not processed** until a worker picks them up
- Rate limit errors (`429`) automatically retry with exponential backoff (60s, 120s, 240s)
- Each job handles one batch of up to `translator.ai.batch_size` keys (default: 50)
- The `Language` model and `TranslationRequest` DTO are stored as plain primitives in the job to avoid PHP serialization issues with `readonly` properties

---

### Translation cache

The AI cache stores translated values after each API call. On subsequent runs, cached keys are returned instantly without an API call, saving both time and cost.

**Cache key format:**

```
{prefix}:{target_locale}:{key_name}:{md5(source_value)}
```

The MD5 of the source value means the cache is **automatically invalidated** when a source string changes — you never serve a stale translation for an edited source.

**Configure the cache:**

```env
# Enable/disable
TRANSLATOR_AI_CACHE_ENABLED=true

# TTL in seconds (default: 86400 = 24 hours)
TRANSLATOR_AI_CACHE_TTL=86400

# Set to 0 for indefinite storage
TRANSLATOR_AI_CACHE_TTL=0

# Use a specific cache store (redis, memcached, file, etc.)
TRANSLATOR_AI_CACHE_STORE=redis
```

**Bypass the cache for one run:**

```bash
php artisan translator:ai-translate --target=ar --fresh-cache
```

**Clear all AI translation cache:**

```bash
php artisan cache:clear
```

---

## Models

### `Language`

```php
use Syriable\Translator\Models\Language;

// All active languages
Language::query()->active()->get();

// The source language
Language::query()->source()->first();

// Right-to-left languages
Language::query()->rtl()->get();

// Domain methods
$language->isRtl();    // bool
$language->isSource(); // bool
```

### `Group`

```php
use Syriable\Translator\Models\Group;

// Application groups only (no vendor namespace)
Group::query()->application()->get();

// Vendor groups
Group::query()->vendor()->get();

// Groups by file format
Group::query()->withFormat('php')->get();
Group::query()->withFormat('json')->get();

// Domain methods
$group->isVendor();        // bool
$group->isJson();          // bool
$group->qualifiedName();   // 'auth' or 'spatie::permissions'
```

### `TranslationKey`

```php
use Syriable\Translator\Models\TranslationKey;

// Keys that have interpolation parameters
TranslationKey::query()->withParameters()->get();

// Keys using plural pipe syntax
TranslationKey::query()->plural()->get();

// Keys containing HTML
TranslationKey::query()->html()->get();

// Domain methods
$key->hasParameters();  // bool
$key->parameterNames(); // [':name', '{count}']
```

### `Translation`

```php
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Enums\TranslationStatus;

// By status
Translation::query()->untranslated()->get();
Translation::query()->translated()->get();
Translation::query()->reviewed()->get();
Translation::query()->withStatus(TranslationStatus::Reviewed)->get();

// By language
Translation::query()->source()->get();       // Source language only
Translation::query()->forLocale('ar')->get(); // Specific locale

// Domain methods
$translation->hasValue();  // bool
$translation->isComplete(); // bool — true for Translated and Reviewed
```

### `AITranslationLog`

```php
use Syriable\Translator\Models\AITranslationLog;

// By provider
AITranslationLog::query()->forProvider('claude')->get();

// By language pair
AITranslationLog::query()->forLanguagePair('en', 'ar')->get();

// Runs with failures
AITranslationLog::query()->withFailures()->get();

// Domain methods
$log->totalTokensUsed();        // int
$log->formattedActualCost();    // '$0.0126'
$log->formattedEstimatedCost(); // '$0.0120'
$log->costVariancePercent();    // float — e.g. +5.0
$log->successRate();            // float — e.g. 97.5
$log->hadFailures();            // bool
```

---

## Events

### `ImportCompleted`

Dispatched after every successful import run. Carries the `ImportLog` record.

```php
use Syriable\Translator\Events\ImportCompleted;
use Syriable\Translator\Models\ImportLog;

Event::listen(ImportCompleted::class, function (ImportCompleted $event): void {
    $log = $event->log; // ImportLog instance

    logger()->info('Import finished', [
        'keys'     => $log->key_count,
        'inserted' => $log->new_count,
        'updated'  => $log->updated_count,
        'duration' => $log->formattedDuration(),
    ]);
});
```

Disable this event in config:

```php
'events' => ['import_completed' => false],
```

### `ExportCompleted`

Dispatched after every successful export run. Carries the `ExportLog` record.

```php
use Syriable\Translator\Events\ExportCompleted;

Event::listen(ExportCompleted::class, function (ExportCompleted $event): void {
    $log = $event->log; // ExportLog instance

    logger()->info('Export finished', [
        'files'    => $log->file_count,
        'duration' => $log->formattedDuration(),
    ]);
});
```

---

## Validation Rules

Two validation rules are included for use in forms that allow translators to edit translation values.

### `TranslationParametersRule`

Fails when a submitted translation value is missing one or more of the interpolation parameters defined on the source key.

```php
use Syriable\Translator\Rules\TranslationParametersRule;

$request->validate([
    'value' => ['nullable', 'string', new TranslationParametersRule($translationKey)],
]);
```

Use the static helper to check for missing parameters programmatically:

```php
$missing = TranslationParametersRule::missingParametersFor($translationKey, $submittedValue);
// Returns e.g. [':name', '{count}'] — the tokens absent from the submitted value
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

Override any model with your own subclass to add custom behaviour, relationships, observers, or casts without modifying package code.

**Example — add a custom scope and observer to `Translation`:**

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

    public function scopePendingReview($query)
    {
        return $query->translated()->where('updated_at', '<', now()->subDays(7));
    }
}
```

Register it in `config/translator.php`:

```php
'models' => [
    'translation' => App\Models\Translation::class,
],
```

All package services — the importer, exporter, replicator, and AI translator — will resolve and use your custom class automatically.

### Custom table prefix

Change the prefix before running migrations for the first time:

```env
TRANSLATOR_TABLE_PREFIX=mytrans_
```

All seven package tables will be created with `mytrans_` instead of `ltu_`. The prefix is resolved at runtime via the `HasTranslatorTable` trait, so changing it in config immediately affects all model queries.

> **Do not change the prefix after migrations have run.** You would need to rename tables manually.

---

## Environment Variable Reference

| Variable                             | Default             | Description                           |
| ------------------------------------ | ------------------- | ------------------------------------- |
| `TRANSLATOR_SOURCE_LANGUAGE`         | `en`                | BCP 47 code of the source language    |
| `TRANSLATOR_LANG_PATH`               | `null`              | Override the lang directory path      |
| `TRANSLATOR_TABLE_PREFIX`            | `ltu_`              | Database table prefix                 |
| `TRANSLATOR_IMPORT_OVERWRITE`        | `true`              | Overwrite existing values on import   |
| `TRANSLATOR_SCAN_VENDOR`             | `true`              | Scan `lang/vendor` during import      |
| `TRANSLATOR_DETECT_PARAMETERS`       | `true`              | Detect `:param` and `{param}` tokens  |
| `TRANSLATOR_DETECT_HTML`             | `true`              | Detect inline HTML in source strings  |
| `TRANSLATOR_DETECT_PLURAL`           | `true`              | Detect pipe plural syntax             |
| `TRANSLATOR_CHUNK_SIZE`              | `500`               | Keys per DB chunk during replication  |
| `TRANSLATOR_EXPORT_SORT_KEYS`        | `true`              | Sort keys alphabetically on export    |
| `TRANSLATOR_EXPORT_REQUIRE_APPROVAL` | `false`             | Only export Reviewed translations     |
| `TRANSLATOR_CACHE_ENABLED`           | `true`              | Enable translation output caching     |
| `TRANSLATOR_CACHE_TTL`               | `3600`              | Cache TTL in seconds                  |
| `TRANSLATOR_LOG_RETENTION_DAYS`      | `90`                | Days to retain import/export logs     |
| `TRANSLATOR_EVENT_IMPORT_COMPLETED`  | `true`              | Dispatch `ImportCompleted` event      |
| `TRANSLATOR_EVENT_EXPORT_COMPLETED`  | `true`              | Dispatch `ExportCompleted` event      |
| `TRANSLATOR_AI_PROVIDER`             | `claude`            | Default AI provider                   |
| `TRANSLATOR_AI_QUEUE`                | `default`           | Queue name for AI translation jobs    |
| `TRANSLATOR_AI_BATCH_SIZE`           | `50`                | Max keys per API request              |
| `TRANSLATOR_AI_CACHE_ENABLED`        | `true`              | Cache AI translation results          |
| `TRANSLATOR_AI_CACHE_TTL`            | `86400`             | AI cache TTL in seconds (0 = forever) |
| `ANTHROPIC_API_KEY`                  | —                   | Anthropic Claude API key              |
| `ANTHROPIC_MODEL`                    | `claude-sonnet-4-6` | Claude model to use                   |
| `ANTHROPIC_MAX_TOKENS`               | `4096`              | Max output tokens per request         |
| `ANTHROPIC_TIMEOUT`                  | `120`               | Request timeout in seconds            |
| `ANTHROPIC_MAX_RETRIES`              | `3`                 | Retries on transient failures         |
| `OPENAI_API_KEY`                     | —                   | OpenAI API key (ChatGPT driver)       |

---

## Troubleshooting

### `$0.0000` actual cost — nothing saved to database

The AI cache was hit from a previous run. Cached keys are returned without an API call, which is correct. To force a fresh call:

```bash
php artisan translator:ai-translate --target=ar --fresh-cache
```

If the database was still not updated after a non-cached run, check your `ANTHROPIC_API_KEY` and run:

```bash
php artisan translator:queue-check
```

---

### Jobs dispatched but nothing in the `jobs` table

Your `QUEUE_CONNECTION` is `sync`. With the sync driver, jobs execute immediately and are never stored. The command will detect this and warn you. To use real queuing:

```env
QUEUE_CONNECTION=database
```

Then:

```bash
php artisan queue:table
php artisan migrate
php artisan config:clear
php artisan translator:queue-check  # verify
```

---

### `php artisan queue:work` shows no jobs

Run the diagnostic:

```bash
php artisan translator:queue-check --dispatch-test
```

Common causes:

- `QUEUE_CONNECTION` not cleared from config cache — run `php artisan config:clear`
- Worker listening on the wrong queue — use `--queue=default` (or whatever `TRANSLATOR_AI_QUEUE` is set to)
- `jobs` table in a different database connection than expected

---

### Translation shows as complete but values are wrong or empty

The source key may have been translated with an incorrect result and cached. Clear the cache and re-run:

```bash
php artisan translator:ai-translate --target=ar --fresh-cache
```

---

### "No untranslated keys found" but keys exist in the database

Keys already have a `translated` or `reviewed` status. The command only translates keys with `untranslated` status or a `null` value. If you want to re-translate already-translated keys, reset them first via Tinker:

```bash
php artisan tinker --execute="
    \Syriable\Translator\Models\Translation::query()
        ->whereHas('language', fn(\$q) => \$q->where('code', 'ar'))
        ->update(['status' => 'untranslated', 'value' => null]);
"
```

Then re-run the translator.

---

### Provider authentication error

```
[claude] Invalid or missing Anthropic API key.
```

Add your API key to `.env`:

```env
ANTHROPIC_API_KEY=sk-ant-api03-...
```

Then clear config cache:

```bash
php artisan config:clear
```

---

### Rate limit errors (`429`)

When running synchronously, the command retries automatically up to `ANTHROPIC_MAX_RETRIES` times with exponential backoff. When using `--queue`, the job releases itself back onto the queue with 60s/120s/240s delays.

If you hit rate limits frequently, reduce the batch size:

```env
TRANSLATOR_AI_BATCH_SIZE=20
```

---

## License

MIT
