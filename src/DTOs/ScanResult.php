<?php

declare(strict_types=1);

namespace Syriable\Translator\DTOs;

/**
 * Immutable value object representing the outcome of a translation key scan.
 *
 * Produced by TranslationKeyScanner::scan() after comparing keys found in
 * source files against TranslationKey records in the database.
 *
 * - usedKeys:    Every unique translation key call found across all scanned files.
 * - missingKeys: Keys called in code that have no TranslationKey record in the DB.
 * - orphanedKeys: TranslationKey records in the DB that were not found in any source file.
 *
 * Vendor-namespaced groups are excluded from the orphan set because they are
 * owned by external packages, not by application source code.
 *
 * @see \Syriable\Translator\Services\Scanner\TranslationKeyScanner
 */
final readonly class ScanResult
{
    /**
     * @param  string[]  $usedKeys  All unique keys found in source files.
     * @param  string[]  $missingKeys  Keys in code but absent from the database.
     * @param  string[]  $orphanedKeys  Keys in the database but absent from code.
     * @param  int  $fileCount  Number of source files scanned.
     * @param  int  $durationMs  Wall-clock time of the scan in milliseconds.
     */
    public function __construct(
        public array $usedKeys,
        public array $missingKeys,
        public array $orphanedKeys,
        public int $fileCount,
        public int $durationMs,
    ) {}

    /**
     * Determine whether any keys are used in code but absent from the database.
     */
    public function hasMissingKeys(): bool
    {
        return $this->missingKeys !== [];
    }

    /**
     * Determine whether any database keys are absent from all source files.
     */
    public function hasOrphanedKeys(): bool
    {
        return $this->orphanedKeys !== [];
    }

    /**
     * Return the total number of unique keys found in source files.
     */
    public function usedKeyCount(): int
    {
        return count($this->usedKeys);
    }

    /**
     * Return the number of keys missing from the database.
     */
    public function missingKeyCount(): int
    {
        return count($this->missingKeys);
    }

    /**
     * Return the number of orphaned database keys.
     */
    public function orphanedKeyCount(): int
    {
        return count($this->orphanedKeys);
    }

    /**
     * Determine whether the scan found no missing or orphaned keys.
     */
    public function isClean(): bool
    {
        return ! $this->hasMissingKeys() && ! $this->hasOrphanedKeys();
    }
}
