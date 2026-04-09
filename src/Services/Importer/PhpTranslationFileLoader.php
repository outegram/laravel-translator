<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Importer;

use Illuminate\Support\Arr;

/**
 * Loads and parses a single PHP translation file into a flat dot-notation array.
 *
 * Enforces path safety by validating that the resolved file path stays within
 * the permitted base directory before requiring it.
 *
 * Single responsibility: reading one PHP translation file.
 * For directory discovery, see TranslationDirectoryExplorer.
 */
final class PhpTranslationFileLoader
{
    /**
     * Load a PHP translation file and return its contents as a flat dot-notation array.
     *
     * Returns an empty array when:
     *  - The file does not exist or cannot be resolved to a real path.
     *  - The file is not a `.php` file.
     *  - The resolved path escapes the permitted base directory.
     *  - The file does not return an array.
     *
     * @param  string  $filePath  Absolute or relative path to the PHP translation file.
     * @param  string|null  $basePath  Restricts loading to files within this directory.
     *                                 Defaults to the configured lang_path() when null.
     * @return array<string, mixed> Flat dot-notation translation keys and values.
     */
    public function load(string $filePath, ?string $basePath = null): array
    {
        $resolvedFile = realpath($filePath);

        if ($resolvedFile === false || ! is_file($resolvedFile)) {
            return [];
        }

        if (pathinfo($resolvedFile, PATHINFO_EXTENSION) !== 'php') {
            return [];
        }

        if (! $this->isWithinPermittedBase($resolvedFile, $basePath)) {
            return [];
        }

        $content = require $resolvedFile;

        if (! is_array($content)) {
            return [];
        }

        return Arr::dot($content);
    }

    /**
     * Determine whether the resolved file path is within the permitted base directory.
     *
     * @param  string  $resolvedFilePath  Absolute real path to the target file.
     * @param  string|null  $basePath  Restricts loading to files within this directory.
     */
    private function isWithinPermittedBase(string $resolvedFilePath, ?string $basePath): bool
    {
        $configuredLangPath = config('translator.lang_path') ?? lang_path();
        $resolvedBase = realpath($basePath ?? $configuredLangPath);

        if ($resolvedBase === false) {
            return false;
        }

        return str_starts_with(
            $resolvedFilePath,
            rtrim($resolvedBase, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
        );
    }
}
