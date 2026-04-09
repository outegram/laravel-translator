<?php

declare(strict_types=1);

use Syriable\Translator\DTOs\ImportOptions;

describe('ImportOptions', function (): void {

    it('constructs with sensible defaults', function (): void {
        $options = new ImportOptions;

        expect($options->overwrite)->toBeTrue()
            ->and($options->fresh)->toBeFalse()
            ->and($options->source)->toBe('cli')
            ->and($options->triggeredBy)->toBeNull()
            ->and($options->detectParameters)->toBeTrue()
            ->and($options->detectHtml)->toBeTrue()
            ->and($options->detectPlural)->toBeTrue()
            ->and($options->scanVendor)->toBeTrue();
    });

    it('fromConfig() reads detection flags from configuration', function (): void {
        config([
            'translator.import.overwrite' => false,
            'translator.import.detect_parameters' => false,
            'translator.import.detect_html' => true,
            'translator.import.detect_plural' => false,
            'translator.import.scan_vendor' => true,
        ]);

        $options = ImportOptions::fromConfig();

        expect($options->overwrite)->toBeFalse()
            ->and($options->detectParameters)->toBeFalse()
            ->and($options->detectHtml)->toBeTrue()
            ->and($options->detectPlural)->toBeFalse()
            ->and($options->scanVendor)->toBeTrue();
    });

    it('fromConfig() allows runtime overrides to take precedence', function (): void {
        config(['translator.import.overwrite' => true]);

        $options = ImportOptions::fromConfig(['overwrite' => false]);

        expect($options->overwrite)->toBeFalse();
    });

    it('fromConfig() sets triggeredBy from overrides', function (): void {
        $options = ImportOptions::fromConfig(['triggered_by' => 'scheduler']);

        expect($options->triggeredBy)->toBe('scheduler');
    });

    it('fromConfig() sets fresh from overrides', function (): void {
        $options = ImportOptions::fromConfig(['fresh' => true]);

        expect($options->fresh)->toBeTrue();
    });

    it('fromConfig() sets source from overrides', function (): void {
        $options = ImportOptions::fromConfig(['source' => 'ui']);

        expect($options->source)->toBe('ui');
    });
});
