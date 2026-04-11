<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;
use Syriable\Translator\Translation\DatabaseTranslationLoader;

describe('TranslationObserver', function (): void {

    beforeEach(function (): void {
        config([
            'translator.loader.enabled' => true,
            'translator.loader.cache_prefix' => 'obs_test_loader',
            'translator.ai.translation_memory.enabled' => true,
        ]);

        Cache::flush();

        $this->english = Language::factory()->english()->create();
        $this->group = Group::factory()->auth()->create();
        $this->key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'key' => 'failed',
        ]);
        $this->translation = Translation::factory()->translated('Wrong credentials.')->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->english->id,
        ]);
    });

    // -------------------------------------------------------------------------
    // Loader cache invalidation
    // -------------------------------------------------------------------------

    it('clears the loader cache key when a translation is saved', function (): void {
        $cacheKey = DatabaseTranslationLoader::buildCacheKey('en', 'auth', null);

        // Manually seed the cache to simulate a prior load.
        Cache::put($cacheKey, ['failed' => 'Old value.'], 3600);
        expect(Cache::has($cacheKey))->toBeTrue();

        // Trigger the observer via model save.
        $this->translation->update(['value' => 'New value.']);

        expect(Cache::has($cacheKey))->toBeFalse();
    });

    it('clears the loader cache key when a translation is deleted', function (): void {
        $cacheKey = DatabaseTranslationLoader::buildCacheKey('en', 'auth', null);

        Cache::put($cacheKey, ['failed' => 'Old value.'], 3600);
        expect(Cache::has($cacheKey))->toBeTrue();

        $this->translation->delete();

        expect(Cache::has($cacheKey))->toBeFalse();
    });

    it('does not clear the loader cache when loader is disabled', function (): void {
        config(['translator.loader.enabled' => false]);

        $cacheKey = DatabaseTranslationLoader::buildCacheKey('en', 'auth', null);
        Cache::put($cacheKey, ['failed' => 'Cached.'], 3600);

        $this->translation->update(['value' => 'New value.']);

        // Cache should remain untouched because loader is disabled.
        expect(Cache::has($cacheKey))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // Prompt builder memory cache invalidation
    // -------------------------------------------------------------------------

    it('clears prompt memory cache when a translation is marked Reviewed', function (): void {
        $memoryCacheKey = TranslationPromptBuilder::MEMORY_CACHE_PREFIX.':en';

        // Seed the memory cache to simulate a prior prompt build.
        Cache::put($memoryCacheKey, 'cached memory block', 3600);
        expect(Cache::has($memoryCacheKey))->toBeTrue();

        // Marking as Reviewed should clear the memory cache.
        $this->translation->update(['status' => TranslationStatus::Reviewed]);

        expect(Cache::has($memoryCacheKey))->toBeFalse();
    });

    it('does NOT clear prompt memory cache for Translated status updates', function (): void {
        $memoryCacheKey = TranslationPromptBuilder::MEMORY_CACHE_PREFIX.':en';

        Cache::put($memoryCacheKey, 'cached memory block', 3600);

        // A value update without status change should not clear the memory cache.
        $this->translation->update(['value' => 'Updated but still Translated status.']);

        // Memory cache should remain — only Reviewed status triggers invalidation.
        expect(Cache::has($memoryCacheKey))->toBeTrue();
    });

    it('does NOT clear memory cache when translation memory is disabled', function (): void {
        config(['translator.ai.translation_memory.enabled' => false]);

        $memoryCacheKey = TranslationPromptBuilder::MEMORY_CACHE_PREFIX.':en';
        Cache::put($memoryCacheKey, 'cached memory block', 3600);

        $this->translation->update(['status' => TranslationStatus::Reviewed]);

        expect(Cache::has($memoryCacheKey))->toBeTrue();
    });
});
