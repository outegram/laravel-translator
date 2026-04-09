<?php

declare(strict_types=1);

namespace Syriable\Translator\DTOs;

/**
 * Immutable value object representing a single file discovered during a scan.
 *
 * Yielded by FileWalker and passed upstream to scanner or extractor pipelines
 * for further processing. Replaces the raw associative array previously yielded
 * by the walker to provide type safety and IDE support.
 *
 * @see \Syriable\Translator\Services\Scanner\FileWalker
 */
final readonly class ScannedFile
{
    /**
     * @param  string  $absolutePath  The fully resolved, absolute path to the file.
     * @param  string  $relativePath  The path relative to the Laravel application base path.
     */
    public function __construct(
        public string $absolutePath,
        public string $relativePath,
    ) {}
}
