<?php

declare(strict_types=1);

use Syriable\Translator\DTOs\ExportResult;

describe('ExportResult', function (): void {

    it('creates a zero-value instance via empty()', function (): void {
        $result = ExportResult::empty();

        expect($result->localeCount)->toBe(0)
            ->and($result->fileCount)->toBe(0)
            ->and($result->keyCount)->toBe(0)
            ->and($result->durationMs)->toBe(0);
    });

    it('merges counters correctly', function (): void {
        $a = new ExportResult(localeCount: 2, fileCount: 5, keyCount: 100);
        $b = new ExportResult(localeCount: 1, fileCount: 3, keyCount: 50);

        $merged = $a->merge($b);

        expect($merged->localeCount)->toBe(3)
            ->and($merged->fileCount)->toBe(8)
            ->and($merged->keyCount)->toBe(150);
    });

    it('preserves base duration when merging', function (): void {
        $base = new ExportResult(durationMs: 200);
        $other = new ExportResult(durationMs: 100);

        expect($base->merge($other)->durationMs)->toBe(200);
    });

    it('sets duration via withDuration()', function (): void {
        $result = ExportResult::empty()->withDuration(840);

        expect($result->durationMs)->toBe(840);
    });

    it('formats duration as milliseconds for short runs', function (): void {
        expect(new ExportResult(durationMs: 340)->formattedDuration())->toBe('340ms');
    });

    it('formats duration as seconds for long runs', function (): void {
        expect(new ExportResult(durationMs: 2500)->formattedDuration())->toBe('2.5s');
    });

    it('reports hasExportedFiles() correctly', function (): void {
        expect(new ExportResult(fileCount: 3)->hasExportedFiles())->toBeTrue()
            ->and(ExportResult::empty()->hasExportedFiles())->toBeFalse();
    });
});
