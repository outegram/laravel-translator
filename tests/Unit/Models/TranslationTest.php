<?php

declare(strict_types=1);

use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

describe('Translation model', function (): void {

    beforeEach(function (): void {
        $this->language = Language::factory()->french()->create();
        $this->group = Group::factory()->auth()->create();
        $this->key = TranslationKey::factory()->create(['group_id' => $this->group->id]);
    });

    // -------------------------------------------------------------------------
    // Domain methods
    // -------------------------------------------------------------------------

    it('hasValue() returns false when value is null', function (): void {
        $t = Translation::factory()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
            'value' => null,
        ]);

        expect($t->hasValue())->toBeFalse();
    });

    it('hasValue() returns true when value is a non-empty string', function (): void {
        $t = Translation::factory()->translated('Bonjour')->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
        ]);

        expect($t->hasValue())->toBeTrue();
    });

    it('isComplete() returns true for Translated status', function (): void {
        $t = Translation::factory()->translated()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
        ]);

        expect($t->isComplete())->toBeTrue();
    });

    it('isComplete() returns true for Reviewed status', function (): void {
        $t = Translation::factory()->reviewed()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
        ]);

        expect($t->isComplete())->toBeTrue();
    });

    it('isComplete() returns false for Untranslated status', function (): void {
        $t = Translation::factory()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
            'status' => TranslationStatus::Untranslated,
        ]);

        expect($t->isComplete())->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    it('scopeUntranslated returns only Untranslated rows', function (): void {
        Translation::factory()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
            'status' => TranslationStatus::Untranslated,
        ]);

        $key2 = TranslationKey::factory()->create(['group_id' => $this->group->id]);
        Translation::factory()->translated()->create([
            'translation_key_id' => $key2->id,
            'language_id' => $this->language->id,
        ]);

        expect(Translation::query()->untranslated()->count())->toBe(1);
    });

    it('scopeTranslated returns only Translated rows', function (): void {
        Translation::factory()->translated()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
        ]);

        $key2 = TranslationKey::factory()->create(['group_id' => $this->group->id]);
        Translation::factory()->create([
            'translation_key_id' => $key2->id,
            'language_id' => $this->language->id,
            'status' => TranslationStatus::Untranslated,
        ]);

        expect(Translation::query()->translated()->count())->toBe(1);
    });

    it('scopeReviewed returns only Reviewed rows', function (): void {
        Translation::factory()->reviewed()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
        ]);

        expect(Translation::query()->reviewed()->count())->toBe(1);
    });

    it('scopeSource returns translations for the source language', function (): void {
        $english = Language::factory()->english()->create();

        Translation::factory()->translated('The source.')->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $english->id,
        ]);
        Translation::factory()->translated('La traduction.')->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
        ]);

        $sourceRows = Translation::query()->source()->get();

        expect($sourceRows)->toHaveCount(1)
            ->and($sourceRows->first()->value)->toBe('The source.');
    });

    it('scopeForLocale returns translations for the given locale code', function (): void {
        Translation::factory()->translated('La traduction.')->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
        ]);

        expect(Translation::query()->forLocale('fr')->count())->toBe(1)
            ->and(Translation::query()->forLocale('de')->count())->toBe(0);
    });

    it('scopeWithStatus filters by the given TranslationStatus', function (): void {
        Translation::factory()->reviewed()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
        ]);

        expect(Translation::query()->withStatus(TranslationStatus::Reviewed)->count())->toBe(1)
            ->and(Translation::query()->withStatus(TranslationStatus::Untranslated)->count())->toBe(0);
    });

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    it('belongs to a Language', function (): void {
        $t = Translation::factory()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
        ]);

        expect($t->language->id)->toBe($this->language->id);
    });

    it('belongs to a TranslationKey', function (): void {
        $t = Translation::factory()->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->language->id,
        ]);

        expect($t->translationKey->id)->toBe($this->key->id);
    });
});
