<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Syriable\Translator\AI\Contracts\TranslationProviderInterface;
use Syriable\Translator\AI\TranslationProviderManager;
use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

/**
 * Verifies that the concurrency lock in AITranslateCommand prevents two
 * simultaneous translation runs for the same target language from proceeding.
 *
 * WHY THIS MATTERS: Without the lock, two concurrent CLI runs (e.g. cron overlap)
 * would both query the same untranslated keys, make duplicate API calls, and both
 * write identical translations — wasting cost and potentially causing race conditions
 * on the unique (translation_key_id, language_id) constraint.
 *
 * TESTING APPROACH: Locks are acquired/released via Cache, and the array cache
 * driver supports locks in test environments. We acquire the lock manually before
 * running the command to simulate a concurrent process holding it.
 */
describe('AITranslateCommand — concurrency lock', function (): void {

    beforeEach(function (): void {
        config([
            'translator.source_language' => 'en',
            'translator.ai.default_provider' => 'stub',
            'translator.ai.cache.enabled' => false,
            'translator.ai.batch_size' => 50,
            'cache.default' => 'array',
        ]);

        $this->english = Language::factory()->english()->create();
        $this->french = Language::factory()->french()->create();
        $this->group = Group::factory()->auth()->create();
        $this->key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'key' => 'failed',
        ]);

        Translation::factory()->forSource('These credentials do not match.')->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->english->id,
        ]);

        Translation::factory()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->french->id,
            'value' => null,
            'status' => Syriable\Translator\Enums\TranslationStatus::Untranslated,
        ]);

        // Bind a no-op stub provider so any translation that does slip through
        // doesn't make real HTTP calls.
        $stub = new readonly class implements TranslationProviderInterface
        {
            public function estimate(TranslationRequest $r): TranslationEstimate
            {
                return new TranslationEstimate('stub', 'stub-1', 100, 50, 0.001, $r->keyCount(), 10);
            }

            public function translate(TranslationRequest $r): TranslationResponse
            {
                return new TranslationResponse('stub', 'stub-1', [], [], 0, 0, 0.0, 5);
            }

            public function providerName(): string
            {
                return 'stub';
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };

        $manager = Mockery::mock(TranslationProviderManager::class);
        $manager->shouldReceive('driver')->andReturn($stub);
        $this->app->instance(TranslationProviderManager::class, $manager);
    });

    // -------------------------------------------------------------------------
    // Lock acquisition and release
    // -------------------------------------------------------------------------

    it('returns FAILURE immediately when the lock is already held for the target language', function (): void {
        // Simulate a concurrent process holding the lock for French.
        $lock = Cache::lock('translator:ai-translate:fr', seconds: 600);
        $acquired = $lock->get();

        expect($acquired)->toBeTrue(); // Sanity check: we should have the lock.

        try {
            $result = $this->artisan('translator:ai-translate', [
                '--target' => 'fr',
                '--force' => true,
                '--no-interaction' => true,
            ]);

            $result->assertExitCode(1)
                ->expectsOutputToContain('already active');
        } finally {
            $lock->release();
        }
    });

    it('proceeds normally when no lock is held for the target language', function (): void {
        // Verify the lock key does not exist before the command runs.
        expect(Cache::lock('translator:ai-translate:fr')->get())->toBeTrue();
        Cache::lock('translator:ai-translate:fr')->release();

        // Command should run and exit successfully.
        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);
    });

    it('releases the lock after a successful run', function (): void {
        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        // After the command completes, the lock should be available again.
        $lock = Cache::lock('translator:ai-translate:fr', seconds: 10);
        $acquired = $lock->get();

        expect($acquired)->toBeTrue();

        $lock->release();
    });

    it('does not acquire a lock for a different language when one is held for fr', function (): void {
        $arabic = Language::factory()->arabic()->create();

        Translation::factory()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $arabic->id,
            'value' => null,
            'status' => Syriable\Translator\Enums\TranslationStatus::Untranslated,
        ]);

        // Hold the French lock.
        $frLock = Cache::lock('translator:ai-translate:fr', seconds: 600);
        $frLock->get();

        try {
            // Arabic run should proceed — different lock key.
            $this->artisan('translator:ai-translate', [
                '--target' => 'ar',
                '--force' => true,
                '--no-interaction' => true,
            ])->assertExitCode(0);
        } finally {
            $frLock->release();
        }
    });

    // -------------------------------------------------------------------------
    // --no-lock bypass
    // -------------------------------------------------------------------------

    it('bypasses the lock check when --no-lock is passed', function (): void {
        Queue::fake();

        // Hold the lock so the normal path would fail.
        $lock = Cache::lock('translator:ai-translate:fr', seconds: 600);
        $lock->get();

        try {
            // With --no-lock, the command should proceed regardless.
            $this->artisan('translator:ai-translate', [
                '--target' => 'fr',
                '--force' => true,
                '--no-interaction' => true,
                '--no-lock' => true,
            ])->assertExitCode(0);
        } finally {
            $lock->release();
        }
    });

    // -------------------------------------------------------------------------
    // Lock key isolation per language
    // -------------------------------------------------------------------------

    it('uses a language-specific lock key to prevent cross-language interference', function (): void {
        $frLockKey = 'translator:ai-translate:fr';
        $arLockKey = 'translator:ai-translate:ar';

        // Acquire FR lock manually.
        $frLock = Cache::lock($frLockKey, 60);
        expect($frLock->get())->toBeTrue();

        // AR lock should still be available.
        $arLock = Cache::lock($arLockKey, 60);
        expect($arLock->get())->toBeTrue();

        $frLock->release();
        $arLock->release();
    });
});
