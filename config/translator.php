<?php

declare(strict_types=1);

use Syriable\Translator\Models\AITranslationLog;
use Syriable\Translator\Models\ExportLog;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\ImportLog;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

return [

    /*
    |--------------------------------------------------------------------------
    | Source Language
    |--------------------------------------------------------------------------
    */

    'source_language' => env('TRANSLATOR_SOURCE_LANGUAGE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Language Directory Path
    |--------------------------------------------------------------------------
    */

    'lang_path' => env('TRANSLATOR_LANG_PATH', null),

    /*
    |--------------------------------------------------------------------------
    | Database Table Prefix
    |--------------------------------------------------------------------------
    */

    'table_prefix' => env('TRANSLATOR_TABLE_PREFIX', 'ltu_'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    */

    'models' => [
        'language' => Language::class,
        'group' => Group::class,
        'translation_key' => TranslationKey::class,
        'translation' => Translation::class,
        'import_log' => ImportLog::class,
        'export_log' => ExportLog::class,
        'ai_log' => AITranslationLog::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime Translation Loader
    |--------------------------------------------------------------------------
    |
    | When enabled, Laravel's `__()` helper and the `Lang` facade will load
    | translations directly from the database instead of from lang files.
    |
    | This removes the requirement to run `translator:export` before updated
    | translations appear in the application.
    |
    | fallback_to_files — when the database returns no results for a locale/group
    | (e.g. during initial import), fall back to the file-based loader. This
    | makes the transition transparent: enable the loader, run the import, and
    | files serve as a safety net in the meantime.
    |
    | cache_ttl — seconds to cache each locale/group result. Set to 0 to
    | disable caching entirely (not recommended in production).
    |
    | The TranslationObserver automatically invalidates the cache when a
    | Translation model is saved or deleted.
    |
    */

    'loader' => [
        'enabled' => env('TRANSLATOR_LOADER_ENABLED', false),
        'fallback_to_files' => env('TRANSLATOR_LOADER_FALLBACK', true),
        'cache_ttl' => env('TRANSLATOR_LOADER_CACHE_TTL', 3600),
        'cache_prefix' => env('TRANSLATOR_LOADER_CACHE_PREFIX', 'translator_loader'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Import Configuration
    |--------------------------------------------------------------------------
    */

    'import' => [
        'overwrite' => env('TRANSLATOR_IMPORT_OVERWRITE', true),
        'scan_vendor' => env('TRANSLATOR_SCAN_VENDOR', true),
        'detect_parameters' => env('TRANSLATOR_DETECT_PARAMETERS', true),
        'detect_html' => env('TRANSLATOR_DETECT_HTML', true),
        'detect_plural' => env('TRANSLATOR_DETECT_PLURAL', true),
        'exclude_files' => [],
        'chunk_size' => env('TRANSLATOR_CHUNK_SIZE', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Configuration
    |--------------------------------------------------------------------------
    */

    'export' => [
        'sort_keys' => env('TRANSLATOR_EXPORT_SORT_KEYS', true),
        'require_approval' => env('TRANSLATOR_EXPORT_REQUIRE_APPROVAL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scanner Configuration
    |--------------------------------------------------------------------------
    */

    'scanner' => [
        'paths' => [
            app_path(),
            resource_path('views'),
        ],
        'ignore_paths' => [
            'vendor',
            'node_modules',
            'storage',
            '.git',
        ],
        'extensions' => [
            'php',
            'blade.php',
            'js',
            'ts',
            'vue',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'enabled' => env('TRANSLATOR_CACHE_ENABLED', true),
        'store' => env('TRANSLATOR_CACHE_STORE', null),
        'ttl' => env('TRANSLATOR_CACHE_TTL', 3600),
        'prefix' => env('TRANSLATOR_CACHE_PREFIX', 'syriable_translator'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */

    'events' => [
        'import_completed' => env('TRANSLATOR_EVENT_IMPORT_COMPLETED', true),
        'export_completed' => env('TRANSLATOR_EVENT_EXPORT_COMPLETED', true),
        'ai_translation_completed' => env('TRANSLATOR_EVENT_AI_COMPLETED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Retention
    |--------------------------------------------------------------------------
    */

    'log_retention_days' => env('TRANSLATOR_LOG_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | AI Translation Configuration
    |--------------------------------------------------------------------------
    */

    'ai' => [

        'default_provider' => env('TRANSLATOR_AI_PROVIDER', 'claude'),

        'queue' => env('TRANSLATOR_AI_QUEUE', 'default'),

        'batch_size' => env('TRANSLATOR_AI_BATCH_SIZE', 50),

        'default_cost_per_1k_tokens' => 0.005,

        /*
        |----------------------------------------------------------------------
        | Translation Memory
        |----------------------------------------------------------------------
        |
        | When enabled, the AI system prompt is enriched with previously reviewed
        | translations for the target language. Only Reviewed-status translations
        | are used — never Translated — to prevent AI output from feeding back
        | into future AI prompts.
        |
        | cache_ttl — seconds to cache the memory block per locale. The
        | TranslationObserver automatically invalidates the cache when a
        | translation is marked as Reviewed (via model events or the
        | translator:review command).
        |
        | lang_name_cache_ttl — seconds to cache language name lookups.
        | Language names rarely change; 1 hour is safe.
        |
        */

        'translation_memory' => [
            'enabled' => env('TRANSLATOR_AI_MEMORY_ENABLED', true),
            'limit' => env('TRANSLATOR_AI_MEMORY_LIMIT', 20),
            'cache_ttl' => env('TRANSLATOR_AI_MEMORY_CACHE_TTL', 3600),
            'lang_name_cache_ttl' => env('TRANSLATOR_AI_LANG_NAME_CACHE_TTL', 3600),
        ],

        'cache' => [
            'enabled' => env('TRANSLATOR_AI_CACHE_ENABLED', true),
            'ttl' => env('TRANSLATOR_AI_CACHE_TTL', 86400),
            'prefix' => env('TRANSLATOR_AI_CACHE_PREFIX', 'translator_ai'),
        ],

        'token_estimation' => [
            'default_ratio' => env('TRANSLATOR_AI_DEFAULT_RATIO', 4.0),
            'dense_script_ratio' => env('TRANSLATOR_AI_DENSE_RATIO', 2.0),
            'default_expansion_factor' => env('TRANSLATOR_AI_EXPANSION_FACTOR', 1.2),
            'chars_per_token' => [],
            'expansion_factors' => [],
        ],

        /*
        |----------------------------------------------------------------------
        | Providers
        |----------------------------------------------------------------------
        |
        | Add or enable providers here. Each key is the provider name used
        | with --provider= in CLI commands. The ChatGPT driver is shipped but
        | commented out by default — add your OPENAI_API_KEY and uncomment.
        |
        | Pricing reference (verify against current provider docs):
        |   Claude Sonnet 4.6:  $3.00 / $15.00 per 1M tokens (input/output)
        |   GPT-4o:             $2.50 / $10.00 per 1M tokens
        |   Claude Haiku 4.5:   $0.80 / $4.00 per 1M tokens
        |
        */

        'providers' => [

            'claude' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
                'max_tokens' => env('ANTHROPIC_MAX_TOKENS', 4096),
                'timeout_seconds' => env('ANTHROPIC_TIMEOUT', 120),
                'max_retries' => env('ANTHROPIC_MAX_RETRIES', 3),
                'input_cost_per_1k_tokens' => 0.003,
                'output_cost_per_1k_tokens' => 0.015,
            ],

            'chatgpt' => [
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL', 'gpt-4o'),
                'max_tokens' => env('OPENAI_MAX_TOKENS', 4096),
                'timeout_seconds' => env('OPENAI_TIMEOUT', 120),
                'max_retries' => env('OPENAI_MAX_RETRIES', 3),
                'input_cost_per_1k_tokens' => 0.0025,
                'output_cost_per_1k_tokens' => 0.010,
            ],

        ],

    ],

];
