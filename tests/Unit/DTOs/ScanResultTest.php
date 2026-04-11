<?php

declare(strict_types=1);

use Syriable\Translator\DTOs\ScanResult;

describe('ScanResult', function (): void {

    it('reports hasMissingKeys() as false when missing is empty', function (): void {
        $result = new ScanResult(
            usedKeys: ['auth.failed'],
            missingKeys: [],
            orphanedKeys: [],
            fileCount: 5,
            durationMs: 120,
        );

        expect($result->hasMissingKeys())->toBeFalse();
    });

    it('reports hasMissingKeys() as true when missing keys exist', function (): void {
        $result = new ScanResult(
            usedKeys: ['auth.failed'],
            missingKeys: ['auth.failed'],
            orphanedKeys: [],
            fileCount: 5,
            durationMs: 120,
        );

        expect($result->hasMissingKeys())->toBeTrue();
    });

    it('reports hasOrphanedKeys() correctly', function (): void {
        $clean = new ScanResult([], [], [], 0, 0);
        $withOrphans = new ScanResult([], [], ['auth.password'], 5, 100);

        expect($clean->hasOrphanedKeys())->toBeFalse()
            ->and($withOrphans->hasOrphanedKeys())->toBeTrue();
    });

    it('calculates counts correctly', function (): void {
        $result = new ScanResult(
            usedKeys: ['a', 'b', 'c'],
            missingKeys: ['b', 'c'],
            orphanedKeys: ['d'],
            fileCount: 10,
            durationMs: 500,
        );

        expect($result->usedKeyCount())->toBe(3)
            ->and($result->missingKeyCount())->toBe(2)
            ->and($result->orphanedKeyCount())->toBe(1);
    });

    it('isClean() returns true only when both sets are empty', function (): void {
        $clean = new ScanResult(['a'], [], [], 1, 10);
        $missing = new ScanResult(['a', 'b'], ['b'], [], 1, 10);
        $orphaned = new ScanResult(['a'], [], ['z'], 1, 10);
        $both = new ScanResult(['a'], ['b'], ['z'], 1, 10);

        expect($clean->isClean())->toBeTrue()
            ->and($missing->isClean())->toBeFalse()
            ->and($orphaned->isClean())->toBeFalse()
            ->and($both->isClean())->toBeFalse();
    });

    it('uses explicit usedKeyCount when provided and usedKeys array is empty', function (): void {
        $result = new ScanResult(
            usedKeys: [],
            missingKeys: [],
            orphanedKeys: [],
            fileCount: 3,
            durationMs: 100,
            usedKeyCount: 42,
        );

        expect($result->usedKeyCount())->toBe(42);
    });

    it('falls back to count(usedKeys) when usedKeyCount is zero', function (): void {
        $result = new ScanResult(
            usedKeys: ['a', 'b', 'c'],
            missingKeys: [],
            orphanedKeys: [],
            fileCount: 1,
            durationMs: 50,
        );

        expect($result->usedKeyCount())->toBe(3);
    });
});