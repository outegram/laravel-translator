<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

describe('translator:review command', function (): void {

    beforeEach(function (): void {
        Cache::flush();

        $this->french = Language::factory()->french()->create();
        $this->group = Group::factory()->auth()->create();

        $this->keys = TranslationKey::factory()->count(3)->create([
            'group_id' => $this->group->id,
        ]);

        // All three French translations start as Translated (not Reviewed).
        $this->translations = collect($this->keys)->map(fn ($key) => Translation::factory()->translated("French value for {$key->key}.")->create([
            'translation_key_id' => $key->id,
            'language_id' => $this->french->id,
        ])
        );
    });

    // -------------------------------------------------------------------------
    // Basic review flow
    // -------------------------------------------------------------------------

    it('requires --locale to be specified', function (): void {
        $this->artisan('translator:review --no-interaction')
            ->assertExitCode(1);
    });

    it('exits with FAILURE when the locale does not exist', function (): void {
        $this->artisan('translator:review --locale=xx --force --no-interaction')
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('promotes all Translated keys to Reviewed when confirmed', function (): void {
        $this->artisan('translator:review', [
            '--locale' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $reviewedCount = Translation::query()
            ->where('language_id', $this->french->id)
            ->where('status', TranslationStatus::Reviewed)
            ->count();

        expect($reviewedCount)->toBe(3);
    });

    it('does not touch already-reviewed translations', function (): void {
        // Pre-mark one key as reviewed.
        $this->translations->first()->update(['status' => TranslationStatus::Reviewed]);

        $this->artisan('translator:review', [
            '--locale' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        // All 3 should now be Reviewed.
        expect(Translation::where('language_id', $this->french->id)
            ->where('status', TranslationStatus::Reviewed)
            ->count()
        )->toBe(3);
    });

    it('informs user when there are no translated keys to review', function (): void {
        // Change all to Reviewed already.
        Translation::where('language_id', $this->french->id)
            ->update(['status' => TranslationStatus::Reviewed]);

        $this->artisan('translator:review --locale=fr --force --no-interaction')
            ->expectsOutputToContain('Nothing to review')
            ->assertExitCode(0);
    });

    it('shows count of keys to be reviewed', function (): void {
        $this->artisan('translator:review', [
            '--locale' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->expectsOutputToContain('3');
    });

    // -------------------------------------------------------------------------
    // --group filter
    // -------------------------------------------------------------------------

    it('limits review to a specific group when --group is specified', function (): void {
        $otherGroup = Group::factory()->validation()->create();
        $otherKey = TranslationKey::factory()->create(['group_id' => $otherGroup->id]);
        $otherTranslation = Translation::factory()->translated('Other value.')->create([
            'translation_key_id' => $otherKey->id,
            'language_id' => $this->french->id,
        ]);

        $this->artisan('translator:review', [
            '--locale' => 'fr',
            '--group' => 'auth',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        // Auth translations should be reviewed.
        $authReviewed = Translation::where('language_id', $this->french->id)
            ->where('status', TranslationStatus::Reviewed)
            ->whereHas('translationKey', fn ($q) => $q->where('group_id', $this->group->id))
            ->count();

        // Validation translation should remain Translated.
        $otherTranslation->refresh();

        expect($authReviewed)->toBe(3)
            ->and($otherTranslation->status)->toBe(TranslationStatus::Translated);
    });

    // -------------------------------------------------------------------------
    // --dry-run
    // -------------------------------------------------------------------------

    it('reports what would be reviewed without making changes in --dry-run mode', function (): void {
        $this->artisan('translator:review --locale=fr --dry-run --no-interaction')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        // No status should have changed.
        $reviewed = Translation::where('language_id', $this->french->id)
            ->where('status', TranslationStatus::Reviewed)
            ->count();

        expect($reviewed)->toBe(0);
    });

    // -------------------------------------------------------------------------
    // Cache invalidation
    // -------------------------------------------------------------------------

    it('invalidates the AI prompt memory cache after bulk review', function (): void {
        $memoryCacheKey = TranslationPromptBuilder::MEMORY_CACHE_PREFIX.':fr';
        Cache::put($memoryCacheKey, 'stale memory block', 3600);

        $this->artisan('translator:review', [
            '--locale' => 'fr',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        expect(Cache::has($memoryCacheKey))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // Interactive confirmation
    // -------------------------------------------------------------------------

    it('cancels when user declines confirmation in interactive mode', function (): void {
        $this->artisan('translator:review --locale=fr')
            ->expectsQuestion('Mark 3 key(s) as Reviewed?', false)
            ->expectsOutputToContain('cancelled')
            ->assertExitCode(0);

        // No status changes should have occurred.
        $reviewed = Translation::where('language_id', $this->french->id)
            ->where('status', TranslationStatus::Reviewed)
            ->count();

        expect($reviewed)->toBe(0);
    });

    it('proceeds when user confirms in interactive mode', function (): void {
        $this->artisan('translator:review --locale=fr')
            ->expectsQuestion('Mark 3 key(s) as Reviewed?', true)
            ->assertExitCode(0);

        expect(Translation::where('language_id', $this->french->id)
            ->where('status', TranslationStatus::Reviewed)
            ->count()
        )->toBe(3);
    });
});
