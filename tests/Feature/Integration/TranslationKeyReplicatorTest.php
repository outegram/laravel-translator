<?php

declare(strict_types=1);

use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;
use Syriable\Translator\Services\TranslationKeyReplicator;

describe('TranslationKeyReplicator', function (): void {

    beforeEach(function (): void {
        $this->replicator = app(TranslationKeyReplicator::class);

        $this->english = Language::factory()->english()->create();
        $this->french = Language::factory()->french()->create();
        $this->arabic = Language::factory()->arabic()->create();

        $this->group = Group::factory()->auth()->create();
    });

    // -------------------------------------------------------------------------
    // replicateAllKeys()
    // -------------------------------------------------------------------------

    describe('replicateAllKeys()', function (): void {

        it('creates Translation rows for every key × every active language', function (): void {
            TranslationKey::factory()->count(3)->create(['group_id' => $this->group->id]);

            $this->replicator->replicateAllKeys();

            // 3 keys × 3 languages = 9 rows.
            expect(Translation::count())->toBe(9);
        });

        it('sets all newly created translations to Untranslated status', function (): void {
            TranslationKey::factory()->create(['group_id' => $this->group->id]);

            $this->replicator->replicateAllKeys();

            Translation::all()->each(function (Translation $t): void {
                expect($t->status)->toBe(TranslationStatus::Untranslated);
            });
        });

        it('sets all newly created translation values to null', function (): void {
            TranslationKey::factory()->create(['group_id' => $this->group->id]);

            $this->replicator->replicateAllKeys();

            Translation::all()->each(function (Translation $t): void {
                expect($t->value)->toBeNull();
            });
        });

        it('does not overwrite existing Translation rows on re-run', function (): void {
            $key = TranslationKey::factory()->create(['group_id' => $this->group->id]);

            // Pre-populate one translation with a value.
            Translation::factory()->translated('Déjà traduit.')->create([
                'translation_key_id' => $key->id,
                'language_id' => $this->french->id,
            ]);

            $this->replicator->replicateAllKeys();

            $existing = Translation::query()
                ->where('translation_key_id', $key->id)
                ->where('language_id', $this->french->id)
                ->first();

            expect($existing->value)->toBe('Déjà traduit.')
                ->and($existing->status)->toBe(TranslationStatus::Translated);
        });

        it('is idempotent — running twice does not duplicate rows', function (): void {
            TranslationKey::factory()->count(2)->create(['group_id' => $this->group->id]);

            $this->replicator->replicateAllKeys();
            $this->replicator->replicateAllKeys();

            expect(Translation::count())->toBe(6); // 2 keys × 3 languages
        });

        it('skips inactive languages', function (): void {
            $inactive = Language::factory()->inactive()->create();
            TranslationKey::factory()->create(['group_id' => $this->group->id]);

            $this->replicator->replicateAllKeys();

            $inactiveHasRows = Translation::where('language_id', $inactive->id)->exists();
            expect($inactiveHasRows)->toBeFalse();
        });
    });

    // -------------------------------------------------------------------------
    // replicateSingleKey()
    // -------------------------------------------------------------------------

    describe('replicateSingleKey()', function (): void {

        it('creates Translation rows for every active language for a given key', function (): void {
            $key = TranslationKey::factory()->create(['group_id' => $this->group->id]);

            $this->replicator->replicateSingleKey($key);

            expect(Translation::where('translation_key_id', $key->id)->count())->toBe(3);
        });

        it('sets the source language row to Translated when a source value is provided', function (): void {
            $key = TranslationKey::factory()->create(['group_id' => $this->group->id]);

            $this->replicator->replicateSingleKey($key, sourceValue: 'The source text.');

            $sourceRow = Translation::query()
                ->where('translation_key_id', $key->id)
                ->where('language_id', $this->english->id)
                ->first();

            expect($sourceRow->value)->toBe('The source text.')
                ->and($sourceRow->status)->toBe(TranslationStatus::Translated);
        });

        it('keeps non-source language rows as Untranslated', function (): void {
            $key = TranslationKey::factory()->create(['group_id' => $this->group->id]);

            $this->replicator->replicateSingleKey($key, sourceValue: 'The source text.');

            $frRow = Translation::query()
                ->where('translation_key_id', $key->id)
                ->where('language_id', $this->french->id)
                ->first();

            expect($frRow->value)->toBeNull()
                ->and($frRow->status)->toBe(TranslationStatus::Untranslated);
        });

        it('does not overwrite an existing row when called again', function (): void {
            $key = TranslationKey::factory()->create(['group_id' => $this->group->id]);

            Translation::factory()->translated('Existing translation.')->create([
                'translation_key_id' => $key->id,
                'language_id' => $this->french->id,
            ]);

            $this->replicator->replicateSingleKey($key);

            $row = Translation::query()
                ->where('translation_key_id', $key->id)
                ->where('language_id', $this->french->id)
                ->first();

            expect($row->value)->toBe('Existing translation.');
        });
    });

    // -------------------------------------------------------------------------
    // replicateKeysForLanguage()
    // -------------------------------------------------------------------------

    describe('replicateKeysForLanguage()', function (): void {

        it('creates Translation rows for all keys for a newly added language', function (): void {
            TranslationKey::factory()->count(4)->create(['group_id' => $this->group->id]);

            $german = Language::factory()->create(['code' => 'de', 'active' => true]);

            $this->replicator->replicateKeysForLanguage($german);

            expect(Translation::where('language_id', $german->id)->count())->toBe(4);
        });

        it('sets all rows to Untranslated with null values', function (): void {
            TranslationKey::factory()->count(2)->create(['group_id' => $this->group->id]);
            $german = Language::factory()->create(['code' => 'de', 'active' => true]);

            $this->replicator->replicateKeysForLanguage($german);

            Translation::where('language_id', $german->id)->get()->each(function (Translation $t): void {
                expect($t->status)->toBe(TranslationStatus::Untranslated)
                    ->and($t->value)->toBeNull();
            });
        });

        it('is idempotent — does not duplicate rows on repeated calls', function (): void {
            TranslationKey::factory()->count(3)->create(['group_id' => $this->group->id]);
            $german = Language::factory()->create(['code' => 'de', 'active' => true]);

            $this->replicator->replicateKeysForLanguage($german);
            $this->replicator->replicateKeysForLanguage($german);

            expect(Translation::where('language_id', $german->id)->count())->toBe(3);
        });
    });
});
