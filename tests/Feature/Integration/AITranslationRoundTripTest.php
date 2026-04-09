<?php

declare(strict_types=1);

use Syriable\Translator\AI\Contracts\TranslationProviderInterface;
use Syriable\Translator\AI\TranslationProviderManager;
use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\AITranslationLog;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

/**
 * A deterministic stub provider that translates each value by prefixing "FR: "
 * so tests can assert the exact translated output without an API call.
 */
function makeFrenchStub(): TranslationProviderInterface
{
    return new class implements TranslationProviderInterface
    {
        public function estimate(TranslationRequest $request): TranslationEstimate
        {
            return new TranslationEstimate('stub', 'stub-1', 500, 300, 0.005, $request->keyCount(), 100);
        }

        public function translate(TranslationRequest $request): TranslationResponse
        {
            $translations = array_map(
                static fn (string $value): string => 'FR: '.$value,
                $request->keys,
            );

            return new TranslationResponse(
                provider: 'stub',
                model: 'stub-1',
                translations: $translations,
                failedKeys: [],
                inputTokensUsed: 500,
                outputTokensUsed: 300,
                actualCostUsd: 0.005,
                durationMs: 150,
            );
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
}

describe('AI translation round-trip (import → AI translate → export)', function (): void {

    beforeEach(function (): void {
        $this->langDir = sys_get_temp_dir().'/translator_ai_roundtrip_'.uniqid();
        $enDir = $this->langDir.'/en';
        mkdir($enDir, 0755, true);

        file_put_contents($enDir.'/auth.php', <<<'PHP'
        <?php
        return [
            'failed'    => 'These credentials do not match our records.',
            'throttle'  => 'Too many login attempts. Please try again in :seconds seconds.',
        ];
        PHP);

        config([
            'translator.lang_path' => $this->langDir,
            'translator.source_language' => 'en',
            'translator.ai.cache.enabled' => false,
            'translator.ai.default_provider' => 'stub',
            'translator.ai.batch_size' => 50,
        ]);

        // Bind the stub provider.
        $stub = makeFrenchStub();
        $manager = Mockery::mock(TranslationProviderManager::class);
        $manager->shouldReceive('driver')->andReturn($stub);
        $this->app->instance(TranslationProviderManager::class, $manager);
    });

    afterEach(function (): void {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->langDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->langDir);
    });

    it('imports, AI-translates, and exports a complete translation file', function (): void {
        // Step 1 — Import from disk.
        $this->artisan('translator:import')->assertExitCode(0);

        // Add a French language so there are target rows to translate.
        $french = Language::factory()->french()->create();

        // Step 2 — Replicate keys to French.
        $this->artisan('translator:import')->assertExitCode(0);

        // Step 3 — AI translate.
        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        // Step 4 — Verify translations were persisted.
        $failedKey = TranslationKey::where('key', 'failed')->first();
        $frTranslation = Translation::query()
            ->where('translation_key_id', $failedKey->id)
            ->where('language_id', $french->id)
            ->first();

        expect($frTranslation?->value)->toBe('FR: These credentials do not match our records.')
            ->and($frTranslation?->status)->toBe(TranslationStatus::Translated);

        // Step 5 — Export to disk.
        $this->artisan('translator:export --locale=fr')->assertExitCode(0);

        // Step 6 — Verify the exported file contains the AI-translated values.
        $exportedPath = $this->langDir.'/fr/auth.php';
        expect(file_exists($exportedPath))->toBeTrue();

        $exported = require $exportedPath;
        expect($exported['failed'])->toBe('FR: These credentials do not match our records.')
            ->and($exported['throttle'])->toBe('FR: Too many login attempts. Please try again in :seconds seconds.');
    });

    it('creates an AITranslationLog record after AI translation', function (): void {
        $this->artisan('translator:import')->assertExitCode(0);
        Language::factory()->french()->create();
        $this->artisan('translator:import')->assertExitCode(0);

        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        expect(AITranslationLog::count())->toBeGreaterThan(0);

        $log = AITranslationLog::first();
        expect($log->target_language)->toBe('fr')
            ->and($log->provider)->toBe('stub')
            ->and($log->translated_count)->toBeGreaterThan(0)
            ->and($log->failed_count)->toBe(0)
            ->and($log->actual_cost_usd)->toBeGreaterThan(0.0);
    });

    it('preserves parameter tokens through the AI translation pipeline', function (): void {
        $this->artisan('translator:import')->assertExitCode(0);
        Language::factory()->french()->create();
        $this->artisan('translator:import')->assertExitCode(0);

        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $throttleKey = TranslationKey::where('key', 'throttle')->first();
        $frTranslation = Translation::query()
            ->where('translation_key_id', $throttleKey->id)
            ->whereHas('language', static fn ($q) => $q->where('code', 'fr'))
            ->first();

        // The stub prefixes "FR: " but preserves the original content including :seconds.
        expect($frTranslation?->value)->toContain(':seconds');
    });

    it('does not re-translate already translated keys', function (): void {
        $this->artisan('translator:import')->assertExitCode(0);
        $french = Language::factory()->french()->create();
        $this->artisan('translator:import')->assertExitCode(0);

        // First translation pass.
        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $logCountAfterFirst = AITranslationLog::count();

        // Manually set the value to something distinct.
        $failedKey = TranslationKey::where('key', 'failed')->first();
        Translation::query()
            ->where('translation_key_id', $failedKey->id)
            ->where('language_id', $french->id)
            ->update(['value' => 'MANUALLY SET VALUE', 'status' => TranslationStatus::Reviewed->value]);

        // Second pass — already-translated keys should not trigger a new API call
        // (all French rows now have a non-untranslated status, so nothing is queued).
        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        // The manually set value should be preserved (not overwritten).
        $value = Translation::query()
            ->where('translation_key_id', $failedKey->id)
            ->where('language_id', $french->id)
            ->value('value');

        expect($value)->toBe('MANUALLY SET VALUE');
    });
});
