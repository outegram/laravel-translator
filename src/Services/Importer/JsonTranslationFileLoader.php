<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Importer;

use DirectoryIterator;
use JsonException;

/**
 * Handles discovery and loading of JSON-based Laravel translation files.
 *
 * JSON translation files live directly in the lang directory and are named
 * after their locale code (e.g. `en.json`, `ar.json`).
 *
 * Responsibilities:
 *  - Safely load and decode a single JSON translation file.
 *  - Discover all JSON translation files within a lang directory.
 *
 * For PHP translation file loading, see PhpTranslationFileLoader.
 * For PHP translation directory exploration, see TranslationDirectoryExplorer.
 */
final class JsonTranslationFileLoader
{
    /**
     * Load a JSON translation file and return its decoded contents.
     *
     * Returns an empty array when:
     *  - The file does not exist or is not readable.
     *  - The file contents cannot be decoded as a JSON object/array.
     *
     * @param  string  $filePath  Absolute path to the `.json` translation file.
     * @return array<string, mixed> Decoded translation key-value pairs.
     */
    public function load(string $filePath): array
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return [];
        }

        $raw = file_get_contents($filePath);

        if ($raw === false) {
            return [];
        }

        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Discover all JSON translation files within the given lang directory.
     *
     * Each JSON file represents a single locale (e.g. `en.json` → locale `en`).
     * Returns a map of locale code to absolute file path, sorted alphabetically.
     *
     * @param  string  $langPath  Absolute path to the Laravel lang directory.
     * @return array<string, string> Map of locale code => absolute file path.
     */
    public function discoverLocaleFiles(string $langPath): array
    {
        if (! is_dir($langPath)) {
            return [];
        }

        $localeFiles = [];

        foreach (new DirectoryIterator($langPath) as $entry) {
            if ($entry->isDot() || ! $entry->isFile() || $entry->getExtension() !== 'json') {
                continue;
            }

            $locale = $entry->getBasename('.json');
            $localeFiles[$locale] = $entry->getPathname();
        }

        ksort($localeFiles);

        return $localeFiles;
    }
}
