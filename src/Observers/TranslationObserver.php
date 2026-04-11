<?php

declare(strict_types=1);

namespace Syriable\Translator\Observers;

use Illuminate\Support\Facades\Cache;
use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Translation\DatabaseTranslationLoader;

/**
 * Observes Translation model events and invalidates relevant cache entries.
 *
 * Two cache layers require invalidation when a Translation is written:
 *
 * 1. DatabaseTranslationLoader cache:
 *    The loader caches `{locale}:{namespace}:{group}` → key/value maps for up
 *    to `translator.loader.cache_ttl` seconds. When a Translation is saved or
 *    deleted, the cache entry for its locale/group must be cleared so the next
 *    `__()` call returns the updated value immediately.
 *
 * 2. TranslationPromptBuilder memory cache:
 *    The prompt builder caches reviewed translations for use in AI system
 *    prompts (translation memory). This cache is keyed by target locale. When
 *    a Translation is updated to `Reviewed` status, the memory cache for that
 *    locale is invalidated so the next AI translation batch includes the newly
 *    approved example.
 *
 * ### Performance
 *
 * The observer calls `$translation->loadMissing(['language', 'translationKey.group'])`
 * to avoid N+1 queries. Relationships already loaded by the caller are reused.
 * Only two `Cache::forget()` calls are made per save (or one, if the loader is
 * disabled). Cache operations are always faster than the DB write that triggered
 * them, so the observer adds negligible overhead.
 *
 * ### Registration
 *
 * Registered in `TranslatorServiceProvider::registerObservers()` on the model
 * class resolved from `config('translator.models.translation')`. Custom model
 * overrides are fully supported.
 */
final class TranslationObserver
{
    public function saved(Translation $translation): void
    {
        $this->invalidate($translation);
    }

    public function deleted(Translation $translation): void
    {
        $this->invalidate($translation);
    }

    // -------------------------------------------------------------------------
    // Invalidation
    // -------------------------------------------------------------------------

    private function invalidate(Translation $translation): void
    {
        // Load missing relationships in a single query to avoid N+1.
        $translation->loadMissing(['language', 'translationKey.group']);

        $language = $translation->language;
        $translationKey = $translation->translationKey;

        if ($language === null || $translationKey === null) {
            return;
        }

        $group = $translationKey->group;

        if ($group === null) {
            return;
        }

        $locale = $language->code;

        $this->invalidateLoaderCache($locale, $group->name, $group->namespace);
        $this->invalidatePromptBuilderMemory($locale, $translation);
    }

    /**
     * Clear the DatabaseTranslationLoader cache for the affected locale/group.
     *
     * Uses the same `buildCacheKey()` method as the loader itself to guarantee
     * the key format is always in sync.
     */
    private function invalidateLoaderCache(string $locale, string $group, ?string $namespace): void
    {
        if (! config('translator.loader.enabled', false)) {
            return;
        }

        $cacheKey = DatabaseTranslationLoader::buildCacheKey($locale, $group, $namespace);

        Cache::forget($cacheKey);
    }

    /**
     * Clear the TranslationPromptBuilder memory cache when a translation
     * reaches Reviewed status.
     *
     * Only Reviewed translations are included in the AI translation memory,
     * so we only need to invalidate when a translation is approved. Updates
     * to Translated or Untranslated status do not affect the prompt memory.
     */
    private function invalidatePromptBuilderMemory(string $locale, Translation $translation): void
    {
        if (! config('translator.ai.translation_memory.enabled', true)) {
            return;
        }

        if ($translation->status !== TranslationStatus::Reviewed) {
            return;
        }

        $memoryCacheKey = TranslationPromptBuilder::MEMORY_CACHE_PREFIX.":{$locale}";
        Cache::forget($memoryCacheKey);
    }
}
