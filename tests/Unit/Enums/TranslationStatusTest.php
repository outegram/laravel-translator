<?php

declare(strict_types=1);

use Syriable\Translator\Enums\TranslationStatus;

describe('TranslationStatus enum', function (): void {

    it('has the correct raw string values', function (): void {
        expect(TranslationStatus::Untranslated->value)->toBe('untranslated')
            ->and(TranslationStatus::Translated->value)->toBe('translated')
            ->and(TranslationStatus::Reviewed->value)->toBe('reviewed');
    });

    it('label() returns a capitalised human-readable string', function (): void {
        expect(TranslationStatus::Untranslated->label())->toBe('Untranslated')
            ->and(TranslationStatus::Translated->label())->toBe('Translated')
            ->and(TranslationStatus::Reviewed->label())->toBe('Reviewed');
    });

    it('isComplete() returns false for Untranslated', function (): void {
        expect(TranslationStatus::Untranslated->isComplete())->toBeFalse();
    });

    it('isComplete() returns true for Translated', function (): void {
        expect(TranslationStatus::Translated->isComplete())->toBeTrue();
    });

    it('isComplete() returns true for Reviewed', function (): void {
        expect(TranslationStatus::Reviewed->isComplete())->toBeTrue();
    });

    it('can be created from a raw string value', function (): void {
        expect(TranslationStatus::from('translated'))->toBe(TranslationStatus::Translated);
    });

    it('throws on an invalid raw string value', function (): void {
        expect(fn () => TranslationStatus::from('invalid'))->toThrow(ValueError::class);
    });
});
