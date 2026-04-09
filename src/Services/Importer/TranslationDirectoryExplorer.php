<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Importer;

use DirectoryIterator;
use SplFileInfo;

/**
 * Explores a Laravel lang directory structure to discover:
 *  - Available locales (top-level directories, excluding vendor).
 *  - Translation group files for a given locale (keyed by group name).
 *  - Vendor namespaced translation files across all locales.
 *
 * Single responsibility: traversing and mapping the translation directory layout.
 * For file content loading, see PhpTranslationFileLoader.
 */
final class TranslationDirectoryExplorer
{
    private const string VENDOR_DIRECTORY = 'vendor';

    /**
     * Discover all locale codes available within the given lang directory.
     *
     * Excludes dot entries and the reserved `vendor` directory.
     * Returns locales sorted alphabetically.
     *
     * @param  string  $langPath  Absolute path to the Laravel lang directory.
     * @return string[] Sorted list of locale codes (e.g. ['de', 'en', 'fr']).
     */
    public function discoverLocales(string $langPath): array
    {
        if (! is_dir($langPath)) {
            return [];
        }

        $locales = [];

        foreach (new DirectoryIterator($langPath) as $entry) {
            if ($this->shouldSkipDirectory($entry, exclude: [self::VENDOR_DIRECTORY])) {
                continue;
            }

            $locales[] = $entry->getFilename();
        }

        sort($locales);

        return $locales;
    }

    /**
     * Discover all PHP translation files for a given locale within the lang directory.
     *
     * Returns a map of group name (filename without extension) to absolute file path,
     * sorted alphabetically by group name.
     *
     * @param  string  $langPath  Absolute path to the Laravel lang directory.
     * @param  string  $locale  The locale code to scan (e.g. 'en').
     * @return array<string, string> Map of group name => absolute file path.
     */
    public function discoverGroupFiles(string $langPath, string $locale): array
    {
        $localePath = $this->buildPath($langPath, $locale);

        if (! is_dir($localePath)) {
            return [];
        }

        $groups = [];

        foreach (new DirectoryIterator($localePath) as $entry) {
            if ($entry->isDot() || ! $entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $groupName = $entry->getBasename('.php');
            $groups[$groupName] = $entry->getPathname();
        }

        ksort($groups);

        return $groups;
    }

    /**
     * Discover all vendor-namespaced PHP translation files within the lang directory.
     *
     * Returns a nested structure:
     *  namespace => locale => [group => absoluteFilePath]
     *
     * @param  string  $langPath  Absolute path to the Laravel lang directory.
     * @return array<string, array<string, array<string, string>>>
     */
    public function discoverVendorFiles(string $langPath): array
    {
        $vendorPath = $this->buildPath($langPath, self::VENDOR_DIRECTORY);

        if (! is_dir($vendorPath)) {
            return [];
        }

        $namespaces = [];

        foreach (new DirectoryIterator($vendorPath) as $namespaceEntry) {
            if ($this->shouldSkipDirectory($namespaceEntry)) {
                continue;
            }

            $namespace = $namespaceEntry->getFilename();
            $localeFiles = $this->discoverLocaleFilesForNamespace($namespaceEntry->getPathname());

            if (! empty($localeFiles)) {
                $namespaces[$namespace] = $localeFiles;
            }
        }

        return $namespaces;
    }

    /**
     * Collect all locale-to-group-file mappings for a single vendor namespace directory.
     *
     * @return array<string, array<string, string>> Map of locale => [group => absoluteFilePath].
     */
    private function discoverLocaleFilesForNamespace(string $namespacePath): array
    {
        $locales = [];

        foreach (new DirectoryIterator($namespacePath) as $localeEntry) {
            if ($this->shouldSkipDirectory($localeEntry)) {
                continue;
            }

            $locale = $localeEntry->getFilename();
            $groups = $this->discoverGroupFiles($namespacePath, $locale);

            if (! empty($groups)) {
                $locales[$locale] = $groups;
            }
        }

        return $locales;
    }

    /**
     * Determine whether a directory iterator entry should be skipped.
     *
     * @param  string[]  $exclude  Directory names to explicitly exclude.
     */
    private function shouldSkipDirectory(SplFileInfo $entry, array $exclude = []): bool
    {
        if ($entry->isDot() || ! $entry->isDir()) {
            return true;
        }

        return in_array($entry->getFilename(), $exclude, strict: true);
    }

    /**
     * Build an OS-appropriate path by joining segments with the directory separator.
     */
    private function buildPath(string ...$segments): string
    {
        return implode(DIRECTORY_SEPARATOR, $segments);
    }
}
