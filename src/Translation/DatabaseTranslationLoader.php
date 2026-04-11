<?php

declare(strict_types=1);

namespace Syriable\Translator\Translation;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\Cache;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Throwable;

/**
 * Database-backed translation loader for Laravel's translation system.
 *
 * This loader replaces (or wraps) the default file-based loader, allowing
 * translations to be served directly from the database at runtime. This
 * removes the requirement to export translations to disk before they appear
 * in the application.
 *
 * ### Integration
 *
 * Registered via `$this->app->extend('translation.loader', ...)` in the
 * service provider when `translator.loader.enabled = true`. The extend()
 * pattern gives us the existing FileLoader so we can delegate to it on miss.
 *
 * ### Fallback strategy
 *
 * When the database returns an empty result for a locale/group (e.g. during
 * initial import or in test environments), the loader falls back to the
 * wrapped file loader when `translator.loader.fallback_to_files = true`.
 * This makes the upgrade path transparent: enable the DB loader, run import,
 * and file-based fallbacks keep the app working while the DB is populated.
 *
 * ### Caching
 *
 * All database reads are wrapped in `Cache::remember()` with a configurable
 * TTL (`translator.loader.cache_ttl`, default 3600s). Cache keys follow the
 * format:
 *
 *   `{prefix}:{locale}:{namespace}:{group}`
 *
 * The TranslationObserver invalidates the relevant key whenever a Translation
 * model is saved or deleted, ensuring the application sees fresh values without
 * requiring a manual cache flush.
 *
 * ### PHP / Laravel Compatibility
 *
 * The loader implements `Illuminate\Contracts\Translation\Loader` so it is
 * compatible with Laravel 11+ and works with both the `__()` helper and the
 * `Lang` facade without any additional configuration.
 */
final class DatabaseTranslationLoader implements Loader
{
    /** @var array<string, string> */
    private array $namespaces = [];

    /** @var string[] */
    private array $jsonPaths = [];

    /** @var string[] */
    private array $paths = [];

    public function __construct(
        private readonly Loader $fileLoader,
    ) {}

    // -------------------------------------------------------------------------
    // Loader contract
    // -------------------------------------------------------------------------

    /**
     * Load the messages for the given locale.
     *
     * Routes to:
     *  - `loadJsonTranslations()` when group = '*' and namespace = '*'
     *    (Laravel's signal for JSON locale files).
     *  - `loadGroupTranslations()` for named PHP groups.
     *
     * Falls back to the wrapped FileLoader when the DB returns nothing and
     * `translator.loader.fallback_to_files` is enabled.
     *
     * @param  string  $locale
     * @param  string  $group
     * @param  string|null  $namespace
     * @return array<string, mixed>
     */
    public function load($locale, $group, $namespace = null): array
    {
        if ($group === '*' && $namespace === '*') {
            return $this->loadJsonTranslations($locale);
        }

        return $this->loadGroupTranslations($locale, $group, $namespace);
    }

    public function addPath($path): void
    {
        $this->paths[] = $path;
        $this->fileLoader->addPath($path);
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->namespaces[$namespace] = $hint;
        $this->fileLoader->addNamespace($namespace, $hint);
    }

    /**
     * @return array<string, string>
     */
    public function namespaces(): array
    {
        return $this->namespaces;
    }

    public function addJsonPath($path): void
    {
        $this->jsonPaths[] = $path;
        $this->fileLoader->addJsonPath($path);
    }

    // -------------------------------------------------------------------------
    // Database loading
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function loadGroupTranslations(string $locale, string $group, ?string $namespace): array
    {
        $cacheKey = self::buildCacheKey($locale, $group, $namespace);
        $ttl = (int) config('translator.loader.cache_ttl', 3600);

        $result = Cache::remember($cacheKey, $ttl, function () use ($locale, $group, $namespace): array {
            return $this->fetchGroupFromDatabase($locale, $group, $namespace);
        });

        if (empty($result) && $this->shouldFallback()) {
            return $this->fileLoader->load($locale, $group, $namespace);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJsonTranslations(string $locale): array
    {
        $cacheKey = self::buildCacheKey($locale, Group::JSON_GROUP_NAME, null);
        $ttl = (int) config('translator.loader.cache_ttl', 3600);

        $result = Cache::remember($cacheKey, $ttl, function () use ($locale): array {
            return $this->fetchJsonFromDatabase($locale);
        });

        if (empty($result) && $this->shouldFallback()) {
            return $this->fileLoader->load($locale, '*', '*');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchGroupFromDatabase(string $locale, string $group, ?string $namespace): array
    {
        try {
            /** @var Language|null $language */
            $language = Language::query()->where('code', $locale)->first();

            if ($language === null) {
                return [];
            }

            /** @var Group|null $groupRecord */
            $groupRecord = Group::query()
                ->where('name', $group)
                ->where('namespace', $namespace)
                ->first();

            if ($groupRecord === null) {
                return [];
            }

            return Translation::query()
                ->where('language_id', $language->id)
                ->whereHas('translationKey', fn ($q) => $q->where('group_id', $groupRecord->id))
                ->with('translationKey:id,key')
                ->get()
                ->filter(fn (Translation $t): bool => filled($t->value) && $t->translationKey !== null)
                ->mapWithKeys(fn (Translation $t): array => [$t->translationKey->key => $t->value])
                ->all();
        } catch (Throwable) {
            // Database unavailable (during migration, testing, etc.) — return empty
            // and let the fallback to files handle it.
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJsonFromDatabase(string $locale): array
    {
        try {
            /** @var Language|null $language */
            $language = Language::query()->where('code', $locale)->first();

            if ($language === null) {
                return [];
            }

            /** @var Group|null $jsonGroup */
            $jsonGroup = Group::query()
                ->where('name', Group::JSON_GROUP_NAME)
                ->whereNull('namespace')
                ->first();

            if ($jsonGroup === null) {
                return [];
            }

            return Translation::query()
                ->where('language_id', $language->id)
                ->whereHas('translationKey', fn ($q) => $q->where('group_id', $jsonGroup->id))
                ->with('translationKey:id,key')
                ->get()
                ->filter(fn (Translation $t): bool => filled($t->value) && $t->translationKey !== null)
                ->mapWithKeys(fn (Translation $t): array => [$t->translationKey->key => $t->value])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Cache key builder — public static so observers can use the same format
    // -------------------------------------------------------------------------

    /**
     * Build the cache key for a locale + group + namespace combination.
     *
     * Format: `{prefix}:{locale}:{namespace}:{group}`
     *
     * Public static so TranslationObserver can construct the identical key for
     * targeted invalidation without duplicating the key format.
     */
    public static function buildCacheKey(string $locale, string $group, ?string $namespace): string
    {
        $prefix = config('translator.loader.cache_prefix', 'translator_loader');
        $ns = $namespace ?? '_app';

        return "{$prefix}:{$locale}:{$ns}:{$group}";
    }

    /**
     * Flush all loader cache entries for a given locale.
     *
     * Useful when performing a full re-import for a locale. Flushing by prefix
     * requires a cache store that supports tagged or prefix-based clearing.
     * For stores without that support (file, database drivers), individual keys
     * must be cleared by the observer.
     */
    public static function forgetLocale(string $locale): void
    {
        // Per-group invalidation is handled by TranslationObserver on each save.
        // This method exists for bulk flush operations (e.g. after translator:import).
        $prefix = config('translator.loader.cache_prefix', 'translator_loader');

        // For tag-supporting stores (Redis), use tags for bulk invalidation.
        try {
            Cache::tags(["{$prefix}:{$locale}"])->flush();
        } catch (Throwable) {
            // Store doesn't support tags — individual key invalidation via
            // TranslationObserver is the fallback for per-key changes.
        }
    }

    private function shouldFallback(): bool
    {
        return (bool) config('translator.loader.fallback_to_files', true);
    }
}
