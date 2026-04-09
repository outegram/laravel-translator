<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Syriable\Translator\AI\Contracts\TranslationProviderInterface;
use Syriable\Translator\AI\TranslationProviderManager;
use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;
use Syriable\Translator\Services\AI\AITranslationService;

/**
 * Build a stub provider that returns fixed translations and records call counts.
 */
function makeStubProvider(array $translations = [], bool $available = true): TranslationProviderInterface
{
    return new class($translations, $available) implements TranslationProviderInterface
    {
        public int $translateCallCount = 0;

        public function __construct(
            private readonly array $translations,
            private readonly bool $available,
        ) {}

        public function estimate(TranslationRequest $request): TranslationEstimate
        {
            return new TranslationEstimate('stub', 'stub-1', 100, 50, 0.001, $request->keyCount(), 10);
        }

        public function translate(TranslationRequest $request): TranslationResponse
        {
            $this->translateCallCount++;

            $result = array_intersect_key($this->translations, $request->keys);

            return new TranslationResponse(
                provider: 'stub',
                model: 'stub-1',
                translations: $result,
                failedKeys: array_values(array_diff(array_keys($request->keys), array_keys($result))),
                inputTokensUsed: 100,
                outputTokensUsed: 50,
                actualCostUsd: 0.001,
                durationMs: 42,
            );
        }

        public function providerName(): string
        {
            return 'stub';
        }

        public function isAvailable(): bool
        {
            return $this->available;
        }
    };
}

describe('AITranslationService', function (): void {

    beforeEach(function (): void {
        config(['translator.ai.cache.enabled' => false]);
    });

    // -------------------------------------------------------------------------
    // estimate()
    // -------------------------------------------------------------------------

    describe('estimate()', function (): void {

        it('delegates to the provider and returns an estimate without API calls', function (): void {
            $stub = makeStubProvider();
            $manager = Mockery::mock(TranslationProviderManager::class);
            $manager->shouldReceive('driver')->with(null)->andReturn($stub);

            $service = new AITranslationService($manager);
            $request = new TranslationRequest('en', 'fr', ['k' => 'v'], 'group');

            $estimate = $service->estimate($request);

            expect($estimate->provider)->toBe('stub')
                ->and($stub->translateCallCount)->toBe(0);
        });
    });

    // -------------------------------------------------------------------------
    // translate()
    // -------------------------------------------------------------------------

    describe('translate()', function (): void {

        it('calls the provider and persists translated values to the database', function (): void {
            $sourceLanguage = Language::factory()->english()->create();
            $targetLanguage = Language::factory()->french()->create();
            $group = Group::factory()->auth()->create();
            $translationKey = TranslationKey::factory()->create([
                'group_id' => $group->id,
                'key' => 'auth.failed',
            ]);

            // Seed the untranslated target translation row.
            $translation = Translation::factory()->create([
                'translation_key_id' => $translationKey->id,
                'language_id' => $targetLanguage->id,
                'value' => null,
                'status' => TranslationStatus::Untranslated,
            ]);

            $stub = makeStubProvider(['auth.failed' => 'Identifiants incorrects.']);

            $manager = Mockery::mock(TranslationProviderManager::class);
            $manager->shouldReceive('driver')->with('stub')->andReturn($stub);

            $service = new AITranslationService($manager);
            $request = new TranslationRequest('en', 'fr', ['auth.failed' => 'Wrong credentials.'], 'auth');

            $response = $service->translate($request, $targetLanguage, 'stub');

            // Response should carry the translated value.
            expect($response->translations)->toHaveKey('auth.failed', 'Identifiants incorrects.');

            // Database should be updated.
            $translation->refresh();
            expect($translation->value)->toBe('Identifiants incorrects.')
                ->and($translation->status)->toBe(TranslationStatus::Translated);
        });

        it('records an AITranslationLog entry after execution', function (): void {
            $targetLanguage = Language::factory()->french()->create();
            $group = Group::factory()->create();
            $translationKey = TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'some.key']);
            Translation::factory()->create([
                'translation_key_id' => $translationKey->id,
                'language_id' => $targetLanguage->id,
            ]);

            $stub = makeStubProvider(['some.key' => 'Traduction.']);
            $manager = Mockery::mock(TranslationProviderManager::class);
            $manager->shouldReceive('driver')->andReturn($stub);

            $service = new AITranslationService($manager);
            $request = new TranslationRequest('en', 'fr', ['some.key' => 'Translation.'], 'group');

            $service->translate($request, $targetLanguage);

            expect(Syriable\Translator\Models\AITranslationLog::count())->toBe(1);

            $log = Syriable\Translator\Models\AITranslationLog::first();
            expect($log->translated_count)->toBe(1)
                ->and($log->failed_count)->toBe(0)
                ->and($log->target_language)->toBe('fr');
        });
    });

    // -------------------------------------------------------------------------
    // Caching
    // -------------------------------------------------------------------------

    describe('caching', function (): void {

        it('skips the API call entirely when all keys are cached', function (): void {
            config(['translator.ai.cache.enabled' => true]);
            Cache::flush();

            $targetLanguage = Language::factory()->french()->create();

            // Pre-populate the cache.
            $prefix = config('translator.ai.cache.prefix', 'translator_ai');
            $key = 'auth.failed';
            $source = 'Wrong credentials.';
            Cache::put("{$prefix}:fr:{$key}:".md5($source), 'Identifiants incorrects.', 3600);

            $stub = makeStubProvider(['auth.failed' => 'Should not be called.']);
            $manager = Mockery::mock(TranslationProviderManager::class);
            // translate() should NOT be called on the driver when cache hits.
            $manager->shouldReceive('driver')->andReturn($stub);

            $service = new AITranslationService($manager);
            $request = new TranslationRequest('en', 'fr', ['auth.failed' => $source], 'auth');

            $response = $service->translate($request, $targetLanguage);

            expect($stub->translateCallCount)->toBe(0)
                ->and($response->translations)->toHaveKey('auth.failed', 'Identifiants incorrects.')
                ->and($response->model)->toBe('cache');
        });

        it('caches translation results after a successful API call', function (): void {
            config(['translator.ai.cache.enabled' => true]);
            Cache::flush();

            $targetLanguage = Language::factory()->french()->create();
            $group = Group::factory()->create();
            $key = TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'test.key']);
            Translation::factory()->create([
                'translation_key_id' => $key->id,
                'language_id' => $targetLanguage->id,
            ]);

            $stub = makeStubProvider(['test.key' => 'Résultat traduit.']);
            $manager = Mockery::mock(TranslationProviderManager::class);
            $manager->shouldReceive('driver')->andReturn($stub);

            $service = new AITranslationService($manager);
            $request = new TranslationRequest('en', 'fr', ['test.key' => 'Translated result.'], 'test');

            $service->translate($request, $targetLanguage);

            $prefix = config('translator.ai.cache.prefix', 'translator_ai');
            $cacheKey = "{$prefix}:fr:test.key:".md5('Translated result.');
            $cachedValue = Cache::get($cacheKey);

            expect($cachedValue)->toBe('Résultat traduit.');
        });
    });
});
