<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\Cache;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;
use Syriable\Translator\Translation\DatabaseTranslationLoader;

/**
 * Verifies DatabaseTranslationLoader behaviour under various conditions.
 */
describe('DatabaseTranslationLoader', function (): void {

    beforeEach(function (): void {
        config([
            'translator.loader.enabled' => true,
            'translator.loader.cache_ttl' => 3600,
            'translator.loader.cache_prefix' => 'test_loader',
            'translator.loader.fallback_to_files' => false,
        ]);

        Cache::flush();

        // Seed a source language and a standard group.
        $this->english = Language::factory()->english()->create();
        $this->authGroup = Group::factory()->auth()->create();
        $this->failedKey = TranslationKey::factory()->create([
            'group_id' => $this->authGroup->id,
            'key' => 'failed',
        ]);
        $this->translation = Translation::factory()->translated('These credentials do not match.')->create([
            'translation_key_id' => $this->failedKey->id,
            'language_id' => $this->english->id,
        ]);

        // Build loader with a no-op file loader stub.
        $fileLoader = new class implements Loader
        {
            public function load($locale, $group, $namespace = null): array
            {
                return [];
            }

            public function addPath($path): void {}

            public function addNamespace($namespace, $hint): void {}

            public function namespaces(): array
            {
                return [];
            }

            public function addJsonPath($path): void {}
        };

        $this->loader = new DatabaseTranslationLoader($fileLoader);
    });

    // -------------------------------------------------------------------------
    // Basic loading
    // -------------------------------------------------------------------------

    it('loads translations from the database for a known locale and group', function (): void {
        $result = $this->loader->load('en', 'auth', null);

        expect($result)->toHaveKey('failed', 'These credentials do not match.');
    });

    it('returns an empty array when the locale does not exist', function (): void {
        $result = $this->loader->load('xx', 'auth', null);

        expect($result)->toBeEmpty();
    });

    it('returns an empty array when the group does not exist', function (): void {
        $result = $this->loader->load('en', 'nonexistent_group', null);

        expect($result)->toBeEmpty();
    });

    it('returns an empty array when the translation has no value', function (): void {
        // Null out the value
        $this->translation->update(['value' => null, 'status' => TranslationStatus::Untranslated]);

        Cache::flush(); // clear cached result

        $result = $this->loader->load('en', 'auth', null);

        expect($result)->toBeEmpty();
    });

    // -------------------------------------------------------------------------
    // JSON group loading
    // -------------------------------------------------------------------------

    it('loads JSON translations when group = * and namespace = *', function (): void {
        $jsonGroup = Group::factory()->json()->create();
        $jsonKey = TranslationKey::factory()->create([
            'group_id' => $jsonGroup->id,
            'key' => 'Welcome to our app',
        ]);
        Translation::factory()->translated('Welcome to our app.')->create([
            'translation_key_id' => $jsonKey->id,
            'language_id' => $this->english->id,
        ]);

        $result = $this->loader->load('en', '*', '*');

        expect($result)->toHaveKey('Welcome to our app', 'Welcome to our app.');
    });

    // -------------------------------------------------------------------------
    // Caching behaviour
    // -------------------------------------------------------------------------

    it('caches the result on first load', function (): void {
        $cacheKey = DatabaseTranslationLoader::buildCacheKey('en', 'auth', null);

        expect(Cache::has($cacheKey))->toBeFalse();

        $this->loader->load('en', 'auth', null);

        expect(Cache::has($cacheKey))->toBeTrue();
    });

    it('serves subsequent loads from cache without hitting the database', function (): void {
        // First call — populates cache.
        $this->loader->load('en', 'auth', null);

        // Delete the database record — cache should serve the old value.
        $this->translation->delete();

        $result = $this->loader->load('en', 'auth', null);

        expect($result)->toHaveKey('failed', 'These credentials do not match.');
    });

    it('respects cache invalidation after Cache::forget()', function (): void {
        $this->loader->load('en', 'auth', null);

        // Update the value and flush the cache key.
        $this->translation->update(['value' => 'Updated value.']);
        $cacheKey = DatabaseTranslationLoader::buildCacheKey('en', 'auth', null);
        Cache::forget($cacheKey);

        $result = $this->loader->load('en', 'auth', null);

        expect($result)->toHaveKey('failed', 'Updated value.');
    });

    // -------------------------------------------------------------------------
    // Fallback behaviour
    // -------------------------------------------------------------------------

    it('falls back to file loader when DB is empty and fallback_to_files is true', function (): void {
        config(['translator.loader.fallback_to_files' => true]);

        $fileData = ['some_key' => 'File value'];

        $fileLoader = new class($fileData) implements Loader
        {
            public function __construct(private readonly array $data) {}

            public function load($locale, $group, $namespace = null): array
            {
                return $this->data;
            }

            public function addPath($path): void {}

            public function addNamespace($namespace, $hint): void {}

            public function namespaces(): array
            {
                return [];
            }

            public function addJsonPath($path): void {}
        };

        $loader = new DatabaseTranslationLoader($fileLoader);

        // Load a group with no DB translations
        $result = $loader->load('en', 'nonexistent', null);

        expect($result)->toHaveKey('some_key', 'File value');
    });

    it('does not fall back when fallback_to_files is false', function (): void {
        config(['translator.loader.fallback_to_files' => false]);

        $fileLoader = new class implements Loader
        {
            public function load($locale, $group, $namespace = null): array
            {
                return ['file_key' => 'File value'];
            }

            public function addPath($path): void {}

            public function addNamespace($namespace, $hint): void {}

            public function namespaces(): array
            {
                return [];
            }

            public function addJsonPath($path): void {}
        };

        $loader = new DatabaseTranslationLoader($fileLoader);
        $result = $loader->load('en', 'nonexistent', null);

        // No DB data and no fallback → empty result.
        expect($result)->toBeEmpty();
    });

    // -------------------------------------------------------------------------
    // Cache key format
    // -------------------------------------------------------------------------

    it('builds cache keys with the correct format', function (): void {
        $key = DatabaseTranslationLoader::buildCacheKey('en', 'auth', null);

        expect($key)->toBe('test_loader:en:_app:auth');
    });

    it('includes namespace in cache key when present', function (): void {
        $key = DatabaseTranslationLoader::buildCacheKey('en', 'permissions', 'spatie');

        expect($key)->toBe('test_loader:en:spatie:permissions');
    });
});
