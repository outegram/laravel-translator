<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Scanner;

use Syriable\Translator\DTOs\ScanResult;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\TranslationKey;

/**
 * Compares translation keys referenced in application source code against
 * the TranslationKey records persisted in the database.
 *
 * Produces a ScanResult that identifies:
 *  - missingKeys:   Keys called in code with no corresponding database record.
 *  - orphanedKeys:  Database records for keys that no source file references.
 *
 * Vendor-namespaced groups (e.g. spatie::permissions) are excluded from the
 * orphan set because they are owned by external packages, not user code.
 *
 * Configuration is read from config('translator.scanner.*'):
 *  - paths          — directories to scan recursively.
 *  - ignore_paths   — path segments to skip (e.g. 'vendor', 'node_modules').
 *  - extensions     — file extensions to include (e.g. 'php', 'blade.php', 'vue').
 *
 * @see TranslationUsageExtractor
 * @see ScanResult
 */
final readonly class TranslationKeyScanner
{
    public function __construct(
        private FileWalker $walker,
        private TranslationUsageExtractor $extractor,
    ) {}

    /**
     * Execute a full source-code scan and compare results against the database.
     *
     * @return ScanResult Immutable summary of used, missing, and orphaned keys.
     */
    public function scan(): ScanResult
    {
        $startTime = microtime(true);

        [$codeKeys, $fileCount] = $this->resolveCodeKeys();
        $dbKeys = $this->resolveDbKeys();

        $dbKeySet = array_flip($dbKeys);
        $codeKeySet = array_flip($codeKeys);

        // Keys in code but not in the database.
        $missingKeys = array_values(
            array_filter($codeKeys, static fn (string $k): bool => ! isset($dbKeySet[$k])),
        );

        // Keys in the database but not found in any source file.
        $orphanedKeys = array_values(
            array_filter($dbKeys, static fn (string $k): bool => ! isset($codeKeySet[$k])),
        );

        sort($missingKeys);
        sort($orphanedKeys);
        sort($codeKeys);

        return new ScanResult(
            usedKeys: [],
            missingKeys: $missingKeys,
            orphanedKeys: $orphanedKeys,
            fileCount: $fileCount,
            durationMs: (int) ((microtime(true) - $startTime) * 1000),
            usedKeyCount: count($codeKeys),
        );
    }

    // -------------------------------------------------------------------------
    // Source code scanning
    // -------------------------------------------------------------------------

    /**
     * Walk all configured source directories and collect every unique
     * translation key found across all qualifying files.
     *
     * @return array{string[], int} Tuple of [unique sorted keys, file count].
     */
    private function resolveCodeKeys(): array
    {
        $directories = $this->resolveDirectories();
        $ignoredSegments = $this->resolveIgnoredSegments();
        $extensions = $this->resolveExtensions();

        $allKeys = [];
        $fileCount = 0;

        foreach ($this->walker->walk($directories, $ignoredSegments, $extensions) as $file) {
            $fileCount++;
            $keys = $this->extractor->extractFromFile($file);

            foreach ($keys as $key) {
                $allKeys[$key] = true;
            }
        }

        $keys = array_keys($allKeys);
        sort($keys);

        return [$keys, $fileCount];
    }

    // -------------------------------------------------------------------------
    // Database key resolution
    // -------------------------------------------------------------------------

    /**
     * Build the full set of qualified translation key strings from the database.
     *
     * Key format:
     *  - PHP application groups:  "{group_name}.{key}"  (e.g. "auth.failed")
     *  - JSON group (_json):      "{key}"               (e.g. "Welcome to our app")
     *  - Vendor groups:           excluded entirely
     *
     * Uses cursor() to stream rows one at a time, avoiding loading the entire
     * TranslationKey table into memory for large datasets.
     *
     * @return string[] Sorted, unique array of fully-qualified key strings.
     */
    private function resolveDbKeys(): array
    {
        $seen = [];

        TranslationKey::query()
            ->with('group')
            ->cursor()
            ->each(function (TranslationKey $key) use (&$seen): void {
                if ($key->group->isVendor()) {
                    return;
                }

                $qualified = $this->qualifyKey($key);
                $seen[$qualified] = true;
            });

        $keys = array_keys($seen);
        sort($keys);

        return $keys;
    }

    /**
     * Convert a TranslationKey model into its fully-qualified code-call string.
     *
     * JSON keys are returned as-is (they are already the full string used in code).
     * PHP group keys are prefixed with the group name and a dot separator.
     *
     * @param  TranslationKey  $translationKey  The key to qualify.
     */
    private function qualifyKey(TranslationKey $translationKey): string
    {
        $group = $translationKey->group;

        if ($group->name === Group::JSON_GROUP_NAME) {
            return $translationKey->key;
        }

        return $group->name.'.'.$translationKey->key;
    }

    // -------------------------------------------------------------------------
    // Public helpers for the ScanCommand --sync workflow
    // -------------------------------------------------------------------------

    /**
     * Resolve the Group name and TranslationKey key string from a fully-qualified
     * code key, using existing database groups to guide the split point.
     *
     * Examples (assuming group "messages" exists in DB):
     *  "auth.failed"          → ['auth', 'failed']
     *  "messages.inbox.empty" → ['messages', 'inbox.empty']
     *  "Welcome"              → ['_json', 'Welcome']
     *
     * Algorithm:
     *  1. If the key has no dot, it belongs to the JSON group.
     *  2. Otherwise, check whether the first dot-segment matches an existing
     *     application group name. If it does, use that group.
     *  3. If no existing group matches, use the first segment as the group name
     *     (a new PHP group will be created during --sync).
     *
     * @param  string  $qualifiedKey  The fully-qualified key as found in source code.
     * @return array{string, string} Tuple of [group_name, translation_key].
     */
    public function parseKeyComponents(string $qualifiedKey): array
    {
        if (! str_contains($qualifiedKey, '.')) {
            return [Group::JSON_GROUP_NAME, $qualifiedKey];
        }

        $dotPosition = strpos($qualifiedKey, '.');
        $firstSegment = substr($qualifiedKey, 0, $dotPosition);
        $remainder = substr($qualifiedKey, $dotPosition + 1);

        // Check if a group with this name already exists in the DB.
        $groupExists = $this->groupModel()::query()
            ->whereNull('namespace')
            ->where('name', $firstSegment)
            ->exists();

        if ($groupExists) {
            return [$firstSegment, $remainder];
        }

        // No existing group found — treat first segment as a new group name.
        return [$firstSegment, $remainder];
    }

    // -------------------------------------------------------------------------
    // Configuration helpers
    // -------------------------------------------------------------------------

    /**
     * @return string[]
     */
    private function resolveDirectories(): array
    {
        return (array) config('translator.scanner.paths', [
            app_path(),
            resource_path('views'),
        ]);
    }

    /**
     * @return string[]
     */
    private function resolveIgnoredSegments(): array
    {
        return (array) config('translator.scanner.ignore_paths', [
            'vendor',
            'node_modules',
            'storage',
            '.git',
        ]);
    }

    /**
     * @return string[]
     */
    private function resolveExtensions(): array
    {
        return (array) config('translator.scanner.extensions', [
            'php',
            'blade.php',
            'js',
            'ts',
            'vue',
        ]);
    }

    /**
     * @return class-string<Group>
     */
    private function groupModel(): string
    {
        return config('translator.models.group', Group::class);
    }
}