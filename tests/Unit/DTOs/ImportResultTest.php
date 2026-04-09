<?php

declare(strict_types=1);

use Syriable\Translator\DTOs\ImportResult;

describe('ImportResult', function (): void {

    it('creates a zero-value instance via empty()', function (): void {
        $result = ImportResult::empty();

        expect($result->localeCount)->toBe(0)
            ->and($result->keyCount)->toBe(0)
            ->and($result->insertedCount)->toBe(0)
            ->and($result->updatedCount)->toBe(0)
            ->and($result->durationMs)->toBe(0);
    });

    it('merges two results by summing counters', function (): void {
        $a = new ImportResult(localeCount: 2, keyCount: 10, insertedCount: 7, updatedCount: 3);
        $b = new ImportResult(localeCount: 1, keyCount: 5, insertedCount: 4, updatedCount: 1);

        $merged = $a->merge($b);

        expect($merged->localeCount)->toBe(3)
            ->and($merged->keyCount)->toBe(15)
            ->and($merged->insertedCount)->toBe(11)
            ->and($merged->updatedCount)->toBe(4);
    });

    it('does not include duration when merging', function (): void {
        $a = new ImportResult(durationMs: 500);
        $b = new ImportResult(durationMs: 300);

        $merged = $a->merge($b);

        // Duration belongs to the enclosing run, not to sub-results.
        expect($merged->durationMs)->toBe(500);
    });

    it('produces a new instance with the given duration via withDuration()', function (): void {
        $result = ImportResult::empty()->withDuration(1234);

        expect($result->durationMs)->toBe(1234)
            ->and($result->localeCount)->toBe(0);
    });

    it('is immutable — merge returns a new instance', function (): void {
        $original = new ImportResult(keyCount: 10);
        $merged = $original->merge(new ImportResult(keyCount: 5));

        expect($original->keyCount)->toBe(10)
            ->and($merged->keyCount)->toBe(15)
            ->and($merged)->not->toBe($original);
    });

    it('reports hasChanges() correctly', function (): void {
        expect(new ImportResult(insertedCount: 1)->hasChanges())->toBeTrue()
            ->and(new ImportResult(updatedCount: 1)->hasChanges())->toBeTrue()
            ->and(ImportResult::empty()->hasChanges())->toBeFalse();
    });

    it('calculates affectedCount() as inserted + updated', function (): void {
        $result = new ImportResult(insertedCount: 4, updatedCount: 3);

        expect($result->affectedCount())->toBe(7);
    });
});
