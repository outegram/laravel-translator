<?php

declare(strict_types=1);

use Syriable\Translator\Support\PluralFormProvider;

describe('PluralFormProvider', function (): void {

    // -------------------------------------------------------------------------
    // formCount()
    // -------------------------------------------------------------------------

    describe('formCount()', function (): void {

        it('returns 6 for Arabic', function (): void {
            expect(PluralFormProvider::formCount('ar'))->toBe(6);
        });

        it('returns 6 for Welsh', function (): void {
            expect(PluralFormProvider::formCount('cy'))->toBe(6);
        });

        it('returns 5 for Irish', function (): void {
            expect(PluralFormProvider::formCount('ga'))->toBe(5);
        });

        it('returns 4 for Polish', function (): void {
            expect(PluralFormProvider::formCount('pl'))->toBe(4);
        });

        it('returns 4 for Czech', function (): void {
            expect(PluralFormProvider::formCount('cs'))->toBe(4);
        });

        it('returns 4 for Slovenian', function (): void {
            expect(PluralFormProvider::formCount('sl'))->toBe(4);
        });

        it('returns 3 for Russian', function (): void {
            expect(PluralFormProvider::formCount('ru'))->toBe(3);
        });

        it('returns 3 for Ukrainian', function (): void {
            expect(PluralFormProvider::formCount('uk'))->toBe(3);
        });

        it('returns 3 for Romanian', function (): void {
            expect(PluralFormProvider::formCount('ro'))->toBe(3);
        });

        it('returns 3 for Croatian', function (): void {
            expect(PluralFormProvider::formCount('hr'))->toBe(3);
        });

        it('returns 3 for Latvian', function (): void {
            expect(PluralFormProvider::formCount('lv'))->toBe(3);
        });

        it('returns 2 for English', function (): void {
            expect(PluralFormProvider::formCount('en'))->toBe(2);
        });

        it('returns 2 for French', function (): void {
            expect(PluralFormProvider::formCount('fr'))->toBe(2);
        });

        it('returns 2 for German', function (): void {
            expect(PluralFormProvider::formCount('de'))->toBe(2);
        });

        it('returns 2 for Spanish', function (): void {
            expect(PluralFormProvider::formCount('es'))->toBe(2);
        });

        it('returns 1 for Japanese', function (): void {
            expect(PluralFormProvider::formCount('ja'))->toBe(1);
        });

        it('returns 1 for Chinese Simplified', function (): void {
            expect(PluralFormProvider::formCount('zh'))->toBe(1);
        });

        it('returns 1 for Chinese Traditional', function (): void {
            expect(PluralFormProvider::formCount('zh-Hant'))->toBe(1);
        });

        it('returns 1 for Korean', function (): void {
            expect(PluralFormProvider::formCount('ko'))->toBe(1);
        });

        it('returns 1 for Turkish', function (): void {
            expect(PluralFormProvider::formCount('tr'))->toBe(1);
        });

        it('returns 2 as safe default for unknown locales', function (): void {
            expect(PluralFormProvider::formCount('xx'))->toBe(2);
            expect(PluralFormProvider::formCount('zz-ZZ'))->toBe(2);
        });
    });

    // -------------------------------------------------------------------------
    // formNames()
    // -------------------------------------------------------------------------

    describe('formNames()', function (): void {

        it('returns correct CLDR names for Arabic (6 forms)', function (): void {
            expect(PluralFormProvider::formNames('ar'))
                ->toBe(['zero', 'one', 'two', 'few', 'many', 'other']);
        });

        it('returns correct CLDR names for Russian (3 forms)', function (): void {
            expect(PluralFormProvider::formNames('ru'))
                ->toBe(['one', 'few', 'other']);
        });

        it('returns correct CLDR names for English (2 forms)', function (): void {
            expect(PluralFormProvider::formNames('en'))
                ->toBe(['one', 'other']);
        });

        it('returns a single element for Japanese (1 form)', function (): void {
            expect(PluralFormProvider::formNames('ja'))
                ->toBe(['other']);
        });
    });

    // -------------------------------------------------------------------------
    // isSingular()
    // -------------------------------------------------------------------------

    describe('isSingular()', function (): void {

        it('returns true for Japanese', function (): void {
            expect(PluralFormProvider::isSingular('ja'))->toBeTrue();
        });

        it('returns true for Chinese', function (): void {
            expect(PluralFormProvider::isSingular('zh'))->toBeTrue();
        });

        it('returns false for English', function (): void {
            expect(PluralFormProvider::isSingular('en'))->toBeFalse();
        });

        it('returns false for Arabic', function (): void {
            expect(PluralFormProvider::isSingular('ar'))->toBeFalse();
        });
    });

    // -------------------------------------------------------------------------
    // describe()
    // -------------------------------------------------------------------------

    describe('describe()', function (): void {

        it('includes the language name and form count for multi-form languages', function (): void {
            $desc = PluralFormProvider::describe('ar', 'Arabic');

            expect($desc)->toContain('Arabic')
                ->toContain('6')
                ->toContain('zero | one | two | few | many | other');
        });

        it('mentions no pipe separators for singular languages', function (): void {
            $desc = PluralFormProvider::describe('ja', 'Japanese');

            expect($desc)->toContain('1 plural form')
                ->toContain('Do not use pipe separators');
        });

        it('includes the correct form names for Russian', function (): void {
            $desc = PluralFormProvider::describe('ru', 'Russian');

            expect($desc)->toContain('3')
                ->toContain('one | few | other');
        });
    });
});
