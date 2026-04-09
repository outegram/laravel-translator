<?php

declare(strict_types=1);

namespace Syriable\Translator\DTOs;

/**
 * Immutable value object representing the outcome of a translation export operation.
 */
final readonly class ExportResult
{
    public function __construct(
        public int $localeCount = 0,
        public int $fileCount = 0,
        public int $keyCount = 0,
        public int $durationMs = 0,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function merge(self $other): self
    {
        return new self(
            localeCount: $this->localeCount + $other->localeCount,
            fileCount: $this->fileCount + $other->fileCount,
            keyCount: $this->keyCount + $other->keyCount,
            durationMs: $this->durationMs,
        );
    }

    public function withDuration(int $milliseconds): self
    {
        return new self(
            localeCount: $this->localeCount,
            fileCount: $this->fileCount,
            keyCount: $this->keyCount,
            durationMs: $milliseconds,
        );
    }

    public function hasExportedFiles(): bool
    {
        return $this->fileCount > 0;
    }

    public function formattedDuration(): string
    {
        return $this->durationMs >= 1000
            ? round($this->durationMs / 1000, 1).'s'
            : $this->durationMs.'ms';
    }
}
