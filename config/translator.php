<?php

declare(strict_types=1);

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
    |
    | The locale code of the language that serves as the authoritative source
    | for all translations. Read by LanguageResolver and TranslationKeyReplicator.
    |
    */

    'source_language' => env('TRANSLATOR_SOURCE_LANGUAGE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Language Directory Path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the Laravel lang directory. Defaults to lang_path()
    | when null. Read by TranslationImporter and PhpTranslationFileLoader.
    |
    */

    'lang_path' => env('TRANSLATOR_LANG_PATH', null),

    /*
    |--------------------------------------------------------------------------
    | Database Table Prefix
    |--------------------------------------------------------------------------
    |
    | Shared prefix for all package tables. Change only before first migration.
    | Read at runtime by HasTranslatorTable and migration files.
    |
    */

    'table_prefix' => env('TRANSLATOR_TABLE_PREFIX', 'ltu_'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Override any model with a custom subclass to add behaviour without
    | modifying package code. Each class must extend the package model.
    |
    */

    'models' => [
        'language'        => Language::class,
        'group'           => Group::class,
        'translation_key' => TranslationKey::class,
        'translation'     => Translation::class,
        'import_log'      => ImportLog::class,
        'export_log'      => ExportLog::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Import Configuration
    |--------------------------------------------------------------------------
    */

    'import' => [

        // Overwrite existing translation values when re-importing.
        'overwrite' => env('TRANSLATOR_IMPORT_OVERWRITE', true),

        // Traverse lang/vendor and import namespaced package translations.
        'scan_vendor' => env('TRANSLATOR_SCAN_VENDOR', true),

        // Extract :param and {param} tokens and store on TranslationKey.
        'detect_parameters' => env('TRANSLATOR_DETECT_PARAMETERS', true),

        // Flag strings containing inline HTML on TranslationKey.
        'detect_html' => env('TRANSLATOR_DETECT_HTML', true),

        // Detect pipe plural syntax and set is_plural on TranslationKey.
        'detect_plural' => env('TRANSLATOR_DETECT_PLURAL', true),

        // Filenames (with .php extension) to skip during import.
        'exclude_files' => [],

        // Records processed per DB chunk in TranslationKeyReplicator.
        'chunk_size' => env('TRANSLATOR_CHUNK_SIZE', 500),

    ],

    /*
    |--------------------------------------------------------------------------
    | Export Configuration
    |--------------------------------------------------------------------------
    */

    'export' => [

        // Sort translation keys alphabetically in output files.
        'sort_keys' => env('TRANSLATOR_EXPORT_SORT_KEYS', true),

        // Only export keys with Reviewed status (strictest quality gate).
        'require_approval' => env('TRANSLATOR_EXPORT_REQUIRE_APPROVAL', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | Scanner Configuration
    |--------------------------------------------------------------------------
    |
    | Controls FileWalker when scanning source code for __() / trans() usages.
    |
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
        'store'   => env('TRANSLATOR_CACHE_STORE', null),
        'ttl'     => env('TRANSLATOR_CACHE_TTL', 3600),
        'prefix'  => env('TRANSLATOR_CACHE_PREFIX', 'syriable_translator'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */

    'events' => [
        'import_completed'         => env('TRANSLATOR_EVENT_IMPORT_COMPLETED', true),
        'export_completed'         => env('TRANSLATOR_EVENT_EXPORT_COMPLETED', true),

        // Dispatched by AITranslationService after every translation execution.
        // Hook into this from a companion package to send notifications, invalidate
        // caches, or trigger downstream workflows without polling.
        'ai_translation_completed' => env('TRANSLATOR_EVENT_AI_COMPLETED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to retain ImportLog, ExportLog, and AITranslationLog records.
    | The translator:prune-logs command (registered with the scheduler weekly)
    | deletes records older than this threshold. Set to 0 to disable pruning.
    |
    */

    'log_retention_days' => env('TRANSLATOR_LOG_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | AI Translation Configuration
    |--------------------------------------------------------------------------
    */

    'ai' => [

        /*
        |----------------------------------------------------------------------
        | Default Provider
        |----------------------------------------------------------------------
        */

        'default_provider' => env('TRANSLATOR_AI_PROVIDER', 'claude'),

        /*
        |----------------------------------------------------------------------
        | Queue
        |----------------------------------------------------------------------
        */

        'queue' => env('TRANSLATOR_AI_QUEUE', 'default'),

        /*
        |----------------------------------------------------------------------
        | Batch Size
        |----------------------------------------------------------------------
        */

        'batch_size' => env('TRANSLATOR_AI_BATCH_SIZE', 50),

        /*
        |----------------------------------------------------------------------
        | Fallback Cost Rate
        |----------------------------------------------------------------------
        */

        'default_cost_per_1k_tokens' => 0.005,

        /*
        |----------------------------------------------------------------------
        | Translation Memory
        |----------------------------------------------------------------------
        |
        | When enabled, the AI system prompt is enriched with previously reviewed
        | translations for the target language. This improves terminology
        | consistency across batches and across separate translation runs.
        |
        | Only Reviewed-status translations are used as memory — never Translated
        | — to prevent unreviewed AI output from being re-fed into future prompts.
        |
        | limit — Maximum number of reviewed translations to inject per request.
        |         Keep this low enough to stay within your provider's token budget.
        |         Typical recommended range: 10–30.
        |
        */

        'translation_memory' => [
            'enabled' => env('TRANSLATOR_AI_MEMORY_ENABLED', true),
            'limit'   => env('TRANSLATOR_AI_MEMORY_LIMIT', 20),
        ],

        /*
        |----------------------------------------------------------------------
        | AI Translation Cache
        |----------------------------------------------------------------------
        */

        'cache' => [
            'enabled' => env('TRANSLATOR_AI_CACHE_ENABLED', true),
            'ttl'     => env('TRANSLATOR_AI_CACHE_TTL', 86400),
            'prefix'  => env('TRANSLATOR_AI_CACHE_PREFIX', 'translator_ai'),
        ],

        /*
        |----------------------------------------------------------------------
        | Token Estimation
        |----------------------------------------------------------------------
        */

        'token_estimation' => [
            'default_ratio'            => env('TRANSLATOR_AI_DEFAULT_RATIO', 4.0),
            'dense_script_ratio'       => env('TRANSLATOR_AI_DENSE_RATIO', 2.0),
            'default_expansion_factor' => env('TRANSLATOR_AI_EXPANSION_FACTOR', 1.2),
            'chars_per_token'          => [],
            'expansion_factors'        => [],
        ],

        /*
        |----------------------------------------------------------------------
        | Providers
        |----------------------------------------------------------------------
        |
        | Pricing reference (always verify against current provider docs):
        |   Claude Sonnet 4.6:  $3.00 / $15.00 per 1M tokens (input/output)
        |   GPT-4o:             $2.50 / $10.00 per 1M tokens
        |
        */

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

            // 'chatgpt' => [
            //     'api_key'                   => env('OPENAI_API_KEY'),
            //     'model'                     => 'gpt-4o',
            //     'max_tokens'                => 4096,
            //     'timeout_seconds'           => 120,
            //     'max_retries'               => 3,
            //     'input_cost_per_1k_tokens'  => 0.0025,
            //     'output_cost_per_1k_tokens' => 0.010,
            // ],

        ],

    ],

];