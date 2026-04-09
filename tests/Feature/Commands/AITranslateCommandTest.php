<?php

declare(strict_types=1);

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

/**
 * Build a fully controllable stub provider for command-level tests.
 *
 * @param  array<string, string>  $translations  Keys to return as translated.
 */
function makeCommandStubProvider(array $translations, bool $available = true): TranslationProviderInterface
{
    return new readonly class($translations, $available) implements TranslationProviderInterface
    {
        public function __construct(
            private array $translations,
            private bool $available,
        ) {}

        public function estimate(TranslationRequest $request): TranslationEstimate
        {
            return new TranslationEstimate(
                provider: 'stub',
                model: 'stub-1',
                estimatedInputTokens: 200,
                estimatedOutputTokens: 100,
                estimatedCostUsd: 0.0008,
                keyCount: $request->keyCount(),
                sourceCharacters: $request->totalSourceCharacters(),
            );
        }

        public function translate(TranslationRequest $request): TranslationResponse
        {
            $result = array_intersect_key($this->translations, $request->keys);

            return new TranslationResponse(
                provider: 'stub',
                model: 'stub-1',
                translations: $result,
                failedKeys: [],
                inputTokensUsed: 200,
                outputTokensUsed: 100,
                actualCostUsd: 0.0008,
                durationMs: 120,
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

describe('translator:ai-translate command', function (): void {

    beforeEach(function (): void {
        config([
            'translator.source_language' => 'en',
            'translator.ai.default_provider' => 'stub',
            'translator.ai.cache.enabled' => false,
            'translator.ai.batch_size' => 50,
        ]);

        // Seed languages.
        $this->english = Language::factory()->english()->create();
        $this->french = Language::factory()->french()->create();

        // Seed a translation group and key.
        $this->group = Group::factory()->auth()->create();
        $this->key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'key' => 'failed',
        ]);

        // Source (English) translation — the value to be translated.
        Translation::factory()->forSource('These credentials do not match.')->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->english->id,
        ]);

        // Target (French) untranslated row — what the command will fill.
        $this->targetTranslation = Translation::factory()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->french->id,
            'value' => null,
            'status' => TranslationStatus::Untranslated,
        ]);

        // Bind a stub provider into the container.
        $stub = makeCommandStubProvider(['failed' => 'Ces identifiants ne correspondent pas.']);

        $manager = Mockery::mock(TranslationProviderManager::class);
        $manager->shouldReceive('driver')->andReturn($stub);
        $this->app->instance(TranslationProviderManager::class, $manager);
    });

    // -------------------------------------------------------------------------
    // Basic flow
    // -------------------------------------------------------------------------

    it('exits with SUCCESS in non-interactive mode with --force', function (): void {
        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);
    });

    it('displays the cost estimate table before executing', function (): void {
        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Metric')
            ->assertExitCode(0);
    });

    it('persists translated values to the database after execution', function (): void {
        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $this->targetTranslation->refresh();

        expect($this->targetTranslation->value)->toBe('Ces identifiants ne correspondent pas.')
            ->and($this->targetTranslation->status)->toBe(TranslationStatus::Translated);
    });

    it('informs the user when no untranslated keys exist', function (): void {
        // Mark the target translation as already translated.
        $this->targetTranslation->update([
            'value' => 'Already translated.',
            'status' => TranslationStatus::Translated,
        ]);

        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('No untranslated keys')
            ->assertExitCode(0);
    });

    // -------------------------------------------------------------------------
    // Filtering
    // -------------------------------------------------------------------------

    it('only translates keys in the specified --group', function (): void {
        // Create a second group with an untranslated key.
        $otherGroup = Group::factory()->validation()->create();
        $otherKey = TranslationKey::factory()->create([
            'group_id' => $otherGroup->id,
            'key' => 'required',
        ]);
        Translation::factory()->forSource('This field is required.')->create([
            'translation_key_id' => $otherKey->id,
            'language_id' => $this->english->id,
        ]);
        $otherTarget = Translation::factory()->create([
            'translation_key_id' => $otherKey->id,
            'language_id' => $this->french->id,
            'value' => null,
            'status' => TranslationStatus::Untranslated,
        ]);

        // Only translate the 'auth' group.
        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--group' => 'auth',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        // The 'auth' key should be translated; 'validation' key should remain untranslated.
        $this->targetTranslation->refresh();
        $otherTarget->refresh();

        expect($this->targetTranslation->value)->toBe('Ces identifiants ne correspondent pas.')
            ->and($otherTarget->value)->toBeNull();
    });

    // -------------------------------------------------------------------------
    // Queue dispatch
    // -------------------------------------------------------------------------

    it('dispatches jobs to the queue when --queue is passed', function (): void {
        Illuminate\Support\Facades\Queue::fake();

        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--queue' => true,
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        Illuminate\Support\Facades\Queue::assertPushed(
            Syriable\Translator\Jobs\TranslateKeysJob::class,
        );
    });

    // -------------------------------------------------------------------------
    // Provider availability
    // -------------------------------------------------------------------------

    it('exits with FAILURE when the provider is unavailable', function (): void {
        $unavailableStub = makeCommandStubProvider([], available: false);

        $manager = Mockery::mock(TranslationProviderManager::class);
        $manager->shouldReceive('driver')->andReturn($unavailableStub);
        $this->app->instance(TranslationProviderManager::class, $manager);

        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(1);
    });

    // -------------------------------------------------------------------------
    // Cancellation
    // -------------------------------------------------------------------------

    it('cancels gracefully when user declines the cost confirmation', function (): void {
        $this->artisan('translator:ai-translate', ['--target' => 'fr'])
            ->expectsQuestion('Proceed with translation?', false)
            ->expectsOutputToContain('cancelled')
            ->assertExitCode(0);

        // No translation should have been persisted.
        $this->targetTranslation->refresh();
        expect($this->targetTranslation->value)->toBeNull();
    });
});
