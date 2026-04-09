<?php

declare(strict_types=1);

use Syriable\Translator\DTOs\ExportOptions;

describe('ExportOptions', function (): void {

    it('constructs with sensible defaults', function (): void {
        $options = new ExportOptions;

        expect($options->locale)->toBeNull()
            ->and($options->group)->toBeNull()
            ->and($options->sortKeys)->toBeTrue()
            ->and($options->requireApproval)->toBeFalse()
            ->and($options->source)->toBe('cli')
            ->and($options->triggeredBy)->toBeNull();
    });

    it('fromConfig() reads sort_keys and require_approval from configuration', function (): void {
        config([
            'translator.export.sort_keys' => false,
            'translator.export.require_approval' => true,
        ]);

        $options = ExportOptions::fromConfig();

        expect($options->sortKeys)->toBeFalse()
            ->and($options->requireApproval)->toBeTrue();
    });

    it('fromConfig() applies locale and group overrides', function (): void {
        $options = ExportOptions::fromConfig(['locale' => 'ar', 'group' => 'auth']);

        expect($options->locale)->toBe('ar')
            ->and($options->group)->toBe('auth');
    });

    it('fromConfig() sets source and triggeredBy from overrides', function (): void {
        $options = ExportOptions::fromConfig(['source' => 'ui', 'triggered_by' => 'admin@example.com']);

        expect($options->source)->toBe('ui')
            ->and($options->triggeredBy)->toBe('admin@example.com');
    });
});
