<?php

declare(strict_types=1);

namespace Syriable\Translator\DTOs;

/**
 * Immutable value object representing the outcome of a translation import operation.
 *
 * Tracks aggregate counters for locales processed, translation keys evaluated,
 * newly inserted keys, updated keys, and total execution time.
 *
 * Instances are immutable — merging two results produces a new instance rather
 * than mutating either operand. Duration is intentionally excluded from merging,
 * as it represents wall-clock time for a single import run, not an additive metric.
 *
 * Typical usage:
 * ```php
 * $result = ImportResult::empty();
 * $result = $result->merge($localeResult)->withDuration(120);
 * ```
 *
 * @see \Syriable\Translator\Services\Importer\TranslationImporter
 */
final readonly class ImportResult
{
    /**
     * @param  int  $localeCount  Number of locales processed during the import.
     * @param  int  $keyCount  Total number of translation keys evaluated.
     * @param  int  $insertedCount  Number of translation keys newly created.
     * @param  int  $updatedCount  Number of existing translation keys updated.
     * @param  int  $durationMs  Total wall-clock duration of the import in milliseconds.
     */
    public function __construct(
        public int $localeCount = 0,
        public int $keyCount = 0,
        public int $insertedCount = 0,
        public int $updatedCount = 0,
        public int $durationMs = 0,
    ) {}

    /**
     * Create a zero-value ImportResult to use as an accumulator.
     *
     * Preferred over `new ImportResult()` at call sites for semantic clarity.
     */
    public static function empty(): self
    {
        return new self;
    }

    /**
     * Produce a new ImportResult by adding the counters of another result to this one.
     *
     * Duration is not merged — it belongs to the enclosing import run, not to
     * individual sub-results.
     *
     * @param  self  $other  The result to merge into this one.
     * @return self A new instance with combined counter values.
     */
    public function merge(self $other): self
    {
        return new self(
            localeCount: $this->localeCount + $other->localeCount,
            keyCount: $this->keyCount + $other->keyCount,
            insertedCount: $this->insertedCount + $other->insertedCount,
            updatedCount: $this->updatedCount + $other->updatedCount,
            durationMs: $this->durationMs,
        );
    }

    /**
     * Produce a new ImportResult with the duration set to the given value.
     *
     * Separated from the constructor to allow duration to be recorded after
     * all merging is complete, without coupling timing logic to accumulation.
     *
     * @param  int  $milliseconds  Elapsed time in milliseconds.
     * @return self A new instance with the updated duration.
     */
    public function withDuration(int $milliseconds): self
    {
        return new self(
            localeCount: $this->localeCount,
            keyCount: $this->keyCount,
            insertedCount: $this->insertedCount,
            updatedCount: $this->updatedCount,
            durationMs: $milliseconds,
        );
    }

    /**
     * Determine whether the import produced any changes (insertions or updates).
     */
    public function hasChanges(): bool
    {
        return $this->insertedCount > 0 || $this->updatedCount > 0;
    }

    /**
     * Calculate the total number of affected keys (inserted + updated).
     */
    public function affectedCount(): int
    {
        return $this->insertedCount + $this->updatedCount;
    }
}
