<?php

declare(strict_types=1);

use Syriable\Translator\Models\Language;
use Syriable\Translator\Services\Importer\LanguageResolver;

describe('LanguageResolver', function (): void {

    beforeEach(function (): void {
        $this->resolver = app(LanguageResolver::class);
        config(['translator.source_language' => 'en']);
    });

    it('creates a new Language record for an unrecognised locale code', function (): void {
        expect(Language::where('code', 'fr')->exists())->toBeFalse();

        $language = $this->resolver->resolve('fr');

        expect($language->code)->toBe('fr')
            ->and($language->active)->toBeTrue()
            ->and($language->is_source)->toBeFalse();
    });

    it('resolves known locale metadata from LanguageDataProvider', function (): void {
        $language = $this->resolver->resolve('ar');

        expect($language->name)->toBe('Arabic')
            ->and($language->native_name)->toBe('العربية')
            ->and($language->rtl)->toBeTrue();
    });

    it('falls back to the locale code as name when provider has no entry', function (): void {
        // 'xx' is not a recognised BCP 47 code.
        $language = $this->resolver->resolve('xx');

        expect($language->name)->toBe('xx')
            ->and($language->native_name)->toBe('xx');
    });

    it('marks the configured source language as is_source', function (): void {
        $language = $this->resolver->resolve('en');

        expect($language->is_source)->toBeTrue();
    });

    it('does not mark other languages as is_source', function (): void {
        $language = $this->resolver->resolve('de');

        expect($language->is_source)->toBeFalse();
    });

    it('returns the existing Language when the locale already exists', function (): void {
        $existing = Language::factory()->create(['code' => 'fr', 'active' => true]);

        $resolved = $this->resolver->resolve('fr');

        expect($resolved->id)->toBe($existing->id)
            ->and(Language::where('code', 'fr')->count())->toBe(1);
    });

    it('activates an inactive existing language', function (): void {
        Language::factory()->inactive()->create(['code' => 'it']);

        $language = $this->resolver->resolve('it');

        expect($language->active)->toBeTrue();
    });

    it('corrects is_source on an existing language when config changes', function (): void {
        // Language was created without is_source.
        Language::factory()->create(['code' => 'en', 'is_source' => false, 'active' => true]);

        $language = $this->resolver->resolve('en');

        expect($language->is_source)->toBeTrue();
    });

    it('does not perform an update when the language already matches config', function (): void {
        $existing = Language::factory()->english()->create();
        $original = $existing->updated_at;

        // Wait a tick to ensure updated_at would change if touched.
        sleep(1);

        $resolved = $this->resolver->resolve('en');

        // updated_at should not have changed if no update was needed.
        expect($resolved->updated_at->equalTo($original))->toBeTrue();
    });
});
