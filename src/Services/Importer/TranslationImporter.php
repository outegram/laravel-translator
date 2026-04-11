<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Importer;

use Illuminate\Support\Facades\DB;
use Syriable\Translator\Contracts\TranslationImporterContract;
use Syriable\Translator\DTOs\ImportOptions;
use Syriable\Translator\DTOs\ImportResult;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Events\ImportCompleted;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\ImportLog;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;
use Syriable\Translator\Services\TranslationKeyReplicator;

/**
 * Orchestrates a full translation import cycle.
 *
 * Coordinates PHP group file imports, JSON locale file imports, and vendor
 * namespace imports. Delegates file loading, directory discovery, string
 * analysis, language resolution, and key replication to dedicated services.
 *
 * Responsibilities:
 *  - Driving the import sequence.
 *  - Persisting Group, TranslationKey, and Translation records.
 *  - Accumulating an immutable ImportResult across all scopes.
 *  - Logging the completed import and dispatching the ImportCompleted event.
 *
 * All model classes are resolved from config('translator.models.*') to support
 * application-level model overrides.
 */
final readonly class TranslationImporter implements TranslationImporterContract
{
    /**
     * Group name used to store all JSON (non-namespaced) translation keys.
     */
    private const string JSON_GROUP_NAME = '_json';

    /**
     * File format label stored on Group records for PHP translation files.
     */
    private const string FORMAT_PHP = 'php';

    /**
     * File format label stored on Group records for JSON translation files.
     */
    private const string FORMAT_JSON = 'json';

    public function __construct(
        private PhpTranslationFileLoader $phpLoader,
        private TranslationDirectoryExplorer $directoryExplorer,
        private JsonTranslationFileLoader $jsonLoader,
        private TranslationStringAnalyzer $stringAnalyzer,
        private TranslationKeyReplicator $keyReplicator,
        private LanguageResolver $languageResolver,
    ) {}

    /**
     * Execute a full translation import and return the aggregated result.
     *
     * Sequence:
     *  1. Optionally purge all existing translation data (fresh import).
     *  2. Discover and import PHP locale group files.
     *  3. Discover and import JSON locale files.
     *  4. Discover and import vendor-namespaced PHP files (when enabled).
     *  5. Replicate all keys to every active language.
     *  6. Record the import log and dispatch the ImportCompleted event.
     *
     * @param  ImportOptions  $options  Typed configuration for this import run.
     * @return ImportResult Immutable summary of the completed import.
     */
    public function import(ImportOptions $options): ImportResult
    {
        $startTime = microtime(true);

        if ($options->fresh) {
            $this->purgeAllTranslationData();
        }

        $langPath = $this->resolveLangPath();
        $excludedFiles = $this->resolveExcludedFiles();
        $phpLocales = $this->directoryExplorer->discoverLocales($langPath);

        $result = ImportResult::empty()
            ->merge($this->importPhpTranslations($phpLocales, $langPath, $excludedFiles, $options))
            ->merge($this->importJsonTranslations($langPath, $phpLocales, $options))
            ->merge($this->importVendorTranslations($langPath, $excludedFiles, $options));

        $this->keyReplicator->replicateAllKeys();

        $result = $result->withDuration(
            (int) ((microtime(true) - $startTime) * 1000),
        );

        $log = $this->recordImportLog($result, $options);

        if (config('translator.events.import_completed', true)) {
            ImportCompleted::dispatch($log);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Import Scopes
    // -------------------------------------------------------------------------

    /**
     * Import all PHP locale group files from the lang directory.
     *
     * @param  string[]  $phpLocales
     * @param  string[]  $excludedFiles
     */
    private function importPhpTranslations(
        array $phpLocales,
        string $langPath,
        array $excludedFiles,
        ImportOptions $options,
    ): ImportResult {
        $result = ImportResult::empty();

        foreach ($phpLocales as $locale) {
            $language = $this->languageResolver->resolve($locale);
            $groupFiles = $this->directoryExplorer->discoverGroupFiles($langPath, $locale);
            $localeResult = ImportResult::empty();

            foreach ($groupFiles as $groupName => $filePath) {
                if ($this->isFileExcluded($groupName.'.php', $excludedFiles)) {
                    continue;
                }

                $localeResult = $localeResult->merge(
                    $this->importPhpGroupFile($language, $groupName, null, $filePath, $langPath, $options),
                );
            }

            $result = $result->merge(
                new ImportResult(localeCount: 1)->merge($localeResult),
            );
        }

        return $result;
    }

    /**
     * Import all JSON locale files from the lang directory.
     *
     * Only counts a locale toward the total when it was not already discovered
     * during the PHP import phase, preventing double-counting of bilingual locales.
     *
     * @param  string[]  $phpLocales
     */
    private function importJsonTranslations(
        string $langPath,
        array $phpLocales,
        ImportOptions $options,
    ): ImportResult {
        $localeFiles = $this->jsonLoader->discoverLocaleFiles($langPath);
        $result = ImportResult::empty();

        foreach ($localeFiles as $locale => $filePath) {
            $language = $this->languageResolver->resolve($locale);

            $group = $this->groupModel()::query()->firstOrCreate(
                ['name' => self::JSON_GROUP_NAME, 'namespace' => null],
                ['file_format' => self::FORMAT_JSON, 'file_path' => $filePath],
            );

            $translations = $this->jsonLoader->load($filePath);
            $fileResult = $this->persistGroupTranslations($group, $translations, $language, $options);
            $isNewLocale = ! in_array($locale, $phpLocales, strict: true);

            $result = $result->merge(
                new ImportResult(localeCount: $isNewLocale ? 1 : 0)->merge($fileResult),
            );
        }

        return $result;
    }

    /**
     * Import all vendor-namespaced PHP translation files.
     *
     * Skipped entirely when ImportOptions::$scanVendor is false.
     *
     * @param  string[]  $excludedFiles
     */
    private function importVendorTranslations(
        string $langPath,
        array $excludedFiles,
        ImportOptions $options,
    ): ImportResult {
        if (! $options->scanVendor) {
            return ImportResult::empty();
        }

        $vendorFiles = $this->directoryExplorer->discoverVendorFiles($langPath);
        $result = ImportResult::empty();

        foreach ($vendorFiles as $namespace => $vendorLocales) {
            foreach ($vendorLocales as $locale => $groupFiles) {
                $language = $this->languageResolver->resolve($locale);

                foreach ($groupFiles as $groupName => $filePath) {
                    if ($this->isFileExcluded($groupName.'.php', $excludedFiles)) {
                        continue;
                    }

                    $result = $result->merge(
                        $this->importPhpGroupFile($language, $groupName, $namespace, $filePath, $langPath, $options),
                    );
                }
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Group & Key Persistence
    // -------------------------------------------------------------------------

    /**
     * Resolve or create a Group record and import all translations from a PHP file.
     */
    private function importPhpGroupFile(
        Language $language,
        string $groupName,
        ?string $namespace,
        string $filePath,
        string $langPath,
        ImportOptions $options,
    ): ImportResult {
        $group = $this->groupModel()::query()->firstOrCreate(
            ['name' => $groupName, 'namespace' => $namespace],
            ['file_format' => self::FORMAT_PHP, 'file_path' => $filePath],
        );

        $translations = $this->phpLoader->load($filePath, $langPath);

        return $this->persistGroupTranslations($group, $translations, $language, $options);
    }

    /**
     * Persist all translation entries within a group for the given language.
     *
     * Counts all keys in the translations array (including non-string values
     * that are skipped) to accurately reflect the number of keys in the file.
     *
     * @param  array<string, mixed>  $translations  Flat dot-notation key-value pairs.
     */
    private function persistGroupTranslations(
        Group $group,
        array $translations,
        Language $language,
        ImportOptions $options,
    ): ImportResult {
        $keyCount = count($translations);
        $groupResult = ImportResult::empty();

        foreach ($translations as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $groupResult = $groupResult->merge(
                $this->persistTranslation($group, (string) $key, $value, $language, $options),
            );
        }

        return new ImportResult(
            keyCount: $keyCount,
            insertedCount: $groupResult->insertedCount,
            updatedCount: $groupResult->updatedCount,
        );
    }

    /**
     * Resolve or create a TranslationKey and write its Translation value.
     *
     * A key is counted as inserted on first creation. When a key already exists
     * and the language is the source, its metadata is refreshed. The translation
     * value is then written (or conditionally overwritten) via writeTranslationValue().
     */
    private function persistTranslation(
        Group $group,
        string $key,
        string $value,
        Language $language,
        ImportOptions $options,
    ): ImportResult {
        $metadata = $this->resolveStringMetadata($value, $options);

        $translationKey = $this->translationKeyModel()::query()->firstOrCreate(
            ['group_id' => $group->id, 'key' => $key],
            $metadata,
        );

        $insertedCount = 0;

        if ($translationKey->wasRecentlyCreated) {
            $insertedCount = 1;
        } elseif ($language->is_source && filled($value)) {
            $translationKey->update($metadata);
        }

        $wasUpdated = $this->writeTranslationValue($translationKey, $language, $value, $options->overwrite);

        return new ImportResult(
            insertedCount: $insertedCount,
            updatedCount: $wasUpdated ? 1 : 0,
        );
    }

    /**
     * Write or conditionally overwrite a Translation value for a key and language.
     *
     * - When no Translation exists: creates a new record quietly (no model events).
     * - When one exists and overwrite is enabled and the value differs: updates quietly.
     * - Otherwise: no operation.
     *
     * saveQuietly() is used to suppress model events during bulk import,
     * preventing observer overhead for each individual row.
     *
     * @return bool True when an existing record was updated; false otherwise.
     */
    private function writeTranslationValue(
        TranslationKey $translationKey,
        Language $language,
        string $value,
        bool $overwrite,
    ): bool {
        $existing = $this->translationModel()::query()
            ->where('translation_key_id', $translationKey->id)
            ->where('language_id', $language->id)
            ->first();

        if ($existing === null) {
            (new ($this->translationModel())([
                'translation_key_id' => $translationKey->id,
                'language_id' => $language->id,
                'value' => $value,
                'status' => TranslationStatus::Translated,
            ]))->saveQuietly();

            return false;
        }

        if ($overwrite && $existing->value !== $value) {
            $existing->fill([
                'value' => $value,
                'status' => TranslationStatus::Translated,
            ])->saveQuietly();

            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Metadata & Configuration
    // -------------------------------------------------------------------------

    /**
     * Resolve structural metadata for a translation string value.
     *
     * Detection flags are read from ImportOptions — config is resolved once
     * via ImportOptions::fromConfig() rather than on every key.
     *
     * @return array{parameters: string[]|null, is_html: bool, is_plural: bool}
     */
    private function resolveStringMetadata(string $value, ImportOptions $options): array
    {
        $parameters = $options->detectParameters
            ? $this->stringAnalyzer->extractParameters($value)
            : [];

        return [
            'parameters' => $parameters ?: null,
            'is_html' => $options->detectHtml && $this->stringAnalyzer->containsHtml($value),
            'is_plural' => $options->detectPlural && $this->stringAnalyzer->isPlural($value),
        ];
    }

    /**
     * Resolve the lang directory path from configuration.
     */
    private function resolveLangPath(): string
    {
        return config('translator.lang_path') ?? lang_path();
    }

    /**
     * Resolve the list of filenames to exclude from the import.
     *
     * @return string[]
     */
    private function resolveExcludedFiles(): array
    {
        return config('translator.import.exclude_files', []);
    }

    /**
     * Determine whether a filename appears in the excluded files list.
     *
     * @param  string[]  $excludedFiles
     */
    private function isFileExcluded(string $filename, array $excludedFiles): bool
    {
        return in_array($filename, $excludedFiles, strict: true);
    }

    // -------------------------------------------------------------------------
    // Logging & Cleanup
    // -------------------------------------------------------------------------

    /**
     * Persist an ImportLog record summarising the completed import run.
     *
     * Maps insertedCount to `new_count` to preserve the original schema column name.
     */
    private function recordImportLog(ImportResult $result, ImportOptions $options): ImportLog
    {
        return $this->importLogModel()::query()->create([
            'locale_count' => $result->localeCount,
            'key_count' => $result->keyCount,
            'new_count' => $result->insertedCount,
            'updated_count' => $result->updatedCount,
            'duration_ms' => $result->durationMs,
            'triggered_by' => $options->triggeredBy,
            'source' => $options->source,
            'fresh' => $options->fresh,
        ]);
    }

    /**
     * Purge all translation data within a single database transaction.
     *
     * Clears Translation rows first to respect foreign key constraints,
     * then removes TranslationKeys and Groups.
     */
    private function purgeAllTranslationData(): void
    {
        DB::transaction(function (): void {
            $this->translationModel()::query()->delete();
            $this->translationKeyModel()::query()->delete();
            $this->groupModel()::query()->delete();
        });
    }

    // -------------------------------------------------------------------------
    // Model Resolvers
    // -------------------------------------------------------------------------

    /** @return class-string<Group> */
    private function groupModel(): string
    {
        return config('translator.models.group', Group::class);
    }

    /** @return class-string<TranslationKey> */
    private function translationKeyModel(): string
    {
        return config('translator.models.translation_key', TranslationKey::class);
    }

    /** @return class-string<Translation> */
    private function translationModel(): string
    {
        return config('translator.models.translation', Translation::class);
    }

    /** @return class-string<ImportLog> */
    private function importLogModel(): string
    {
        return config('translator.models.import_log', ImportLog::class);
    }
}