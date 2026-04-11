<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
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
 * Regression test for the N+1 query issue in AITranslateCommand::resolveUntranslatedKeys().
 *
 * CONTEXT: The previous implementation used cursor() with with(), which does NOT
 * apply eager loading. Each TranslationKey triggered separate queries for
 * `translations` and `group`, producing O(2n + 1) queries for n keys.
 *
 * The fix uses chunkById(500) which correctly applies the with() constraints per
 * chunk, reducing query count to O(ceil(n/500) * 3) regardless of dataset size.
 *
 * We verify the fix by counting queries for a batch of 20 keys and confirming
 * the count is bounded (not proportional to n).
 */
describe('AITranslateCommand — N+1 query regression', function (): void {

    beforeEach(function (): void {
        config([
            'translator.source_language' => 'en',
            'translator.ai.default_provider' => 'stub',
            'translator.ai.cache.enabled' => false,
            'translator.ai.batch_size' => 50,
        ]);

        $this->english = Language::factory()->english()->create();
        $this->french = Language::factory()->french()->create();
        $this->group = Group::factory()->auth()->create();

        // Seed 20 translation keys — enough to detect a meaningful N+1 regression.
        $this->keys = TranslationKey::factory()->count(20)->create([
            'group_id' => $this->group->id,
        ]);

        foreach ($this->keys as $key) {
            // Source (English) translation for each key.
            Translation::factory()->forSource("Source value for {$key->key}.")->create([
                'translation_key_id' => $key->id,
                'language_id' => $this->english->id,
            ]);

            // Untranslated target (French) row.
            Translation::factory()->create([
                'translation_key_id' => $key->id,
                'language_id' => $this->french->id,
                'value' => null,
                'status' => TranslationStatus::Untranslated,
            ]);
        }

        $stub = new readonly class implements TranslationProviderInterface
        {
            public function estimate(TranslationRequest $r): TranslationEstimate
            {
                return new TranslationEstimate('stub', 'stub-1', 100, 50, 0.0, $r->keyCount(), 10);
            }

            public function translate(TranslationRequest $r): TranslationResponse
            {
                $translations = array_map(
                    static fn (string $v): string => "FR: {$v}",
                    $r->keys,
                );

                return new TranslationResponse('stub', 'stub-1', $translations, [], 200, 100, 0.0, 50);
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
    // Query count is bounded regardless of key count
    // -------------------------------------------------------------------------

    it('uses a bounded number of queries for key discovery (not proportional to n)', function (): void {
        $queryLog = [];

        DB::listen(static function ($query) use (&$queryLog): void {
            $queryLog[] = $query->sql;
        });

        DB::enableQueryLog();

        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // With 20 keys in a single chunk of 500, key discovery should fire:
        // - 1 query for source language lookup
        // - 1 query for target language lookup
        // - 1 chunk query (TranslationKey with eager-loaded translations + group)
        // - 2 relationship queries for the chunk's eager loads
        // That's ~5 queries for discovery — not 40+ (N+1 pattern).
        //
        // The exact count depends on the service's internal operations, but we
        // set a generous upper bound to catch regressions: for 20 keys, N+1
        // would produce > 40 queries for just the discovery phase.
        $discoveryQueryCount = count(array_filter(
            array_column($queries, 'sql'),
            static fn (string $sql): bool => str_contains($sql, 'translation_keys')
                || str_contains($sql, 'translations')
                || str_contains($sql, 'languages')
                || str_contains($sql, 'groups'),
        ));

        // N+1 would produce at least 2*20 = 40 queries for 20 keys.
        // The fixed implementation uses at most 10 queries for 20 keys in one chunk.
        expect($discoveryQueryCount)->toBeLessThan(15);
    });

    it('translates all 20 keys successfully with the chunkById implementation', function (): void {
        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $translatedCount = Translation::query()
            ->where('language_id', $this->french->id)
            ->where('status', TranslationStatus::Translated)
            ->whereNotNull('value')
            ->count();

        expect($translatedCount)->toBe(20);
    });

    it('translates all keys starting with the correct "FR: " prefix', function (): void {
        $this->artisan('translator:ai-translate', [
            '--target' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $translations = Translation::query()
            ->where('language_id', $this->french->id)
            ->whereNotNull('value')
            ->pluck('value');

        foreach ($translations as $value) {
            expect($value)->toStartWith('FR: ');
        }
    });
});
