<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Scanner;

use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Syriable\Translator\DTOs\ScannedFile;

/**
 * Recursively walks through one or more directory paths and yields
 * matching files as ScannedFile DTOs, respecting ignore rules and
 * extension filters.
 *
 * Designed to be stateless and reusable across multiple scan operations.
 * Intended to be consumed by a higher-level scanner or extractor service.
 *
 * Configuration is read from config('translator.scanner.*') by callers
 * before invoking walk() — this class itself has no config dependency.
 */
final class FileWalker
{
    /**
     * Walk through the given directory paths and yield each qualifying file.
     *
     * Files are excluded when:
     *  - Their absolute path contains any of the $ignoredSegments directory names.
     *  - Their extension does not appear in $allowedExtensions (when non-empty).
     *
     * @param  string[]  $directories  Absolute directory paths to scan recursively.
     * @param  string[]  $ignoredSegments  Directory name segments to exclude (e.g. ['vendor', 'node_modules']).
     * @param  string[]  $allowedExtensions  File extensions without a leading dot (e.g. ['php', 'blade.php']).
     * @return Generator<int, ScannedFile>
     */
    public function walk(
        array $directories,
        array $ignoredSegments,
        array $allowedExtensions,
    ): Generator {
        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            yield from $this->walkDirectory(
                directory: $directory,
                scanRoot: $directory,
                ignoredSegments: $ignoredSegments,
                allowedExtensions: $allowedExtensions,
            );
        }
    }

    /**
     * Recursively iterate a single directory and yield qualifying ScannedFile instances.
     *
     * @param  string[]  $ignoredSegments
     * @param  string[]  $allowedExtensions
     * @return Generator<int, ScannedFile>
     */
    private function walkDirectory(
        string $directory,
        string $scanRoot,
        array $ignoredSegments,
        array $allowedExtensions,
    ): Generator {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $absolutePath = $file->getRealPath();

            if ($absolutePath === false) {
                continue;
            }

            if ($this->isIgnored($absolutePath, $ignoredSegments)) {
                continue;
            }

            if (! $this->hasAllowedExtension($absolutePath, $allowedExtensions)) {
                continue;
            }

            yield new ScannedFile(
                absolutePath: $absolutePath,
                relativePath: $this->resolveRelativePath(
                    absolutePath: $absolutePath,
                    scanRoot: $scanRoot,
                ),
            );
        }
    }

    /**
     * Determine whether a file path contains any of the ignored directory segments.
     *
     * Normalises to Unix-style separators before checking to ensure
     * cross-platform compatibility.
     *
     * Uses explicit loops instead of array_any() (PHP 8.4+) for PHP 8.3 compatibility.
     *
     * @param  string[]  $ignoredSegments
     */
    private function isIgnored(string $absolutePath, array $ignoredSegments): bool
    {
        $normalizedPath = str_replace('\\', '/', $absolutePath);

        foreach ($ignoredSegments as $segment) {
            if (str_contains($normalizedPath, '/'.$segment.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a file matches one of the allowed extensions.
     *
     * When no extensions are specified, all files are considered valid.
     *
     * Uses explicit loops instead of array_any() (PHP 8.4+) for PHP 8.3 compatibility.
     *
     * @param  string[]  $allowedExtensions
     */
    private function hasAllowedExtension(string $absolutePath, array $allowedExtensions): bool
    {
        if (empty($allowedExtensions)) {
            return true;
        }

        $normalizedPath = str_replace('\\', '/', $absolutePath);

        foreach ($allowedExtensions as $extension) {
            if (str_ends_with($normalizedPath, '.'.ltrim((string) $extension, '.'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the path of a file relative to the current scan root.
     * Falls back to the normalized absolute path when outside of that root.
     */
    private function resolveRelativePath(string $absolutePath, string $scanRoot): string
    {
        $basePath = rtrim(str_replace('\\', '/', $scanRoot), '/').'/';
        $normalizedPath = str_replace('\\', '/', $absolutePath);

        if (str_starts_with($normalizedPath, $basePath)) {
            return substr($normalizedPath, strlen($basePath));
        }

        return $normalizedPath;
    }
}
