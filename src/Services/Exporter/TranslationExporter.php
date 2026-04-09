<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Exporter;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Syriable\Translator\DTOs\ExportOptions;
use Syriable\Translator\DTOs\ExportResult;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Events\ExportCompleted;
use Syriable\Translator\Models\ExportLog;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;

/**
 * Orchestrates a full translation export cycle.
 *
 * Reads Translation records from the database and writes them back to disk
 * as PHP group files and JSON locale files, preserving the original Laravel
 * file structure including vendor-namespaced paths.
 *
 * Responsibilities:
 *  - Resolving which languages and groups to export.
 *  - Loading translation values via eager-loaded relationships.
 *  - Delegating file writing to PhpFileWriter and JsonFileWriter.
 *  - Accumulating an immutable ExportResult across all scopes.
 *  - Logging the completed export and dispatching ExportCompleted.
 *
 * All model classes are resolved from config('translator.models.*') to support
 * application-level overrides.
 */
final readonly class TranslationExporter
{
    /**
     * Number of Group records processed per database chunk.
     */
    private const int CHUNK_SIZE = 100;

    public function __construct(
        private PhpFileWriter $phpWriter,
        private JsonFileWriter $jsonWriter,
    ) {}

    /**
     * Execute a full translation export and return the aggregated result.
     *
     * Sequence:
     *  1. Resolve the target languages (all active, or a specific locale).
     *  2. For each language, chunk through its groups and write output files.
     *  3. Record the export log and dispatch the ExportCompleted event.
     *
     * @param  ExportOptions  $options  Typed configuration for this export run.
     * @return ExportResult Immutable summary of the completed export.
     */
    public function export(ExportOptions $options): ExportResult
    {
        $startTime = microtime(true);
        $langPath = $this->resolveLangPath();
        $languages = $this->resolveLanguages($options->locale);

        $result = ExportResult::empty();

        foreach ($languages as $language) {
            $result = $result->merge(
                new ExportResult(localeCount: 1)->merge(
                    $this->exportLanguage($language, $langPath, $options),
                ),
            );
        }

        $result = $result->withDuration(
            (int) ((microtime(true) - $startTime) * 1000),
        );

        $log = $this->recordExportLog($result, $options);

        if (config('translator.events.export_completed', true)) {
            ExportCompleted::dispatch($log);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Language & Group Resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the collection of active languages to export.
     *
     * @return Collection<int, Language>
     */
    private function resolveLanguages(?string $locale): Collection
    {
        return $this->languageModel()::query()
            ->active()
            ->when($locale, static fn (Builder $q) => $q->where('code', $locale))
            ->get();
    }

    /**
     * Build the Group query for a given language, eager-loading only the
     * translation values that qualify for export based on their status.
     *
     * When requireApproval is true, only Reviewed translations are included.
     * Otherwise, any non-Untranslated translation is included.
     */
    private function buildGroupQuery(Language $language, ExportOptions $options): Builder
    {
        return $this->groupModel()::query()
            ->with([
                'translationKeys.translations' => function ($query) use ($language, $options): void {
                    $query->where('language_id', $language->id);

                    if ($options->requireApproval) {
                        $query->where('status', TranslationStatus::Reviewed);
                    } else {
                        $query->where('status', '!=', TranslationStatus::Untranslated);
                    }
                },
            ])
            ->when(
                $options->group,
                static fn (Builder $q) => $q->where('name', $options->group),
            );
    }

    // -------------------------------------------------------------------------
    // Export Scopes
    // -------------------------------------------------------------------------

    /**
     * Export all qualifying groups for a single language.
     *
     * Processes groups in chunks to avoid loading the entire dataset into memory.
     */
    private function exportLanguage(
        Language $language,
        string $langPath,
        ExportOptions $options,
    ): ExportResult {
        $languageResult = ExportResult::empty();

        $this->buildGroupQuery($language, $options)->chunkById(
            self::CHUNK_SIZE,
            function (Collection $groups) use ($language, $langPath, $options, &$languageResult): void {
                foreach ($groups as $group) {
                    $languageResult = $languageResult->merge(
                        $this->exportGroup($group, $language, $langPath, $options),
                    );
                }
            },
        );

        return $languageResult;
    }

    /**
     * Export a single Group to disk for the given language.
     *
     * Returns an empty result when the group has no qualifying translations,
     * ensuring no empty files are written.
     */
    private function exportGroup(
        Group $group,
        Language $language,
        string $langPath,
        ExportOptions $options,
    ): ExportResult {
        $translations = $this->collectTranslations($group);

        if (empty($translations)) {
            return ExportResult::empty();
        }

        $this->writeGroupFile($group, $language, $langPath, $translations, $options);

        return new ExportResult(
            keyCount: count($translations),
            fileCount: 1,
        );
    }

    // -------------------------------------------------------------------------
    // File Writing
    // -------------------------------------------------------------------------

    /**
     * Write a group's translations to the appropriate file format and path.
     *
     * JSON groups → `{langPath}/{locale}.json`
     * PHP app groups → `{langPath}/{locale}/{group}.php`
     * PHP vendor groups → `{langPath}/vendor/{namespace}/{locale}/{group}.php`
     *
     * @param  array<string, string>  $translations
     */
    private function writeGroupFile(
        Group $group,
        Language $language,
        string $langPath,
        array $translations,
        ExportOptions $options,
    ): void {
        if ($group->isJson()) {
            $this->jsonWriter->write(
                filePath: $this->buildJsonPath($langPath, $language->code),
                translations: $translations,
                sortKeys: $options->sortKeys,
            );

            return;
        }

        $this->phpWriter->write(
            filePath: $this->buildPhpPath($langPath, $language->code, $group),
            translations: $translations,
            sortKeys: $options->sortKeys,
        );
    }

    /**
     * Build the output path for a JSON locale file.
     */
    private function buildJsonPath(string $langPath, string $localeCode): string
    {
        return $langPath.DIRECTORY_SEPARATOR.$localeCode.'.json';
    }

    /**
     * Build the output path for a PHP translation group file.
     *
     * Application: `{langPath}/{locale}/{group}.php`
     * Vendor:      `{langPath}/vendor/{namespace}/{locale}/{group}.php`
     */
    private function buildPhpPath(string $langPath, string $localeCode, Group $group): string
    {
        $base = $group->namespace
            ? implode(DIRECTORY_SEPARATOR, [$langPath, 'vendor', $group->namespace, $localeCode])
            : implode(DIRECTORY_SEPARATOR, [$langPath, $localeCode]);

        return $base.DIRECTORY_SEPARATOR.$group->name.'.php';
    }

    // -------------------------------------------------------------------------
    // Translation Collection
    // -------------------------------------------------------------------------

    /**
     * Collect qualifying translation key-value pairs from an eager-loaded Group.
     *
     * Only keys with a non-null translation value are included. Keys without
     * a qualifying translation row are silently skipped.
     *
     * @return array<string, string> Flat key => translated-value map.
     */
    private function collectTranslations(Group $group): array
    {
        $translations = [];

        foreach ($group->translationKeys as $translationKey) {
            $translation = $translationKey->translations->first();

            if ($translation !== null && $translation->value !== null) {
                $translations[$translationKey->key] = $translation->value;
            }
        }

        return $translations;
    }

    // -------------------------------------------------------------------------
    // Logging & Configuration
    // -------------------------------------------------------------------------

    /**
     * Persist an ExportLog record summarising the completed export run.
     */
    private function recordExportLog(ExportResult $result, ExportOptions $options): ExportLog
    {
        return $this->exportLogModel()::query()->create([
            'locale_count' => $result->localeCount,
            'file_count' => $result->fileCount,
            'key_count' => $result->keyCount,
            'duration_ms' => $result->durationMs,
            'triggered_by' => $options->triggeredBy,
            'source' => $options->source,
        ]);
    }

    /**
     * Resolve the lang directory path from configuration.
     */
    private function resolveLangPath(): string
    {
        return config('translator.lang_path') ?? lang_path();
    }

    // -------------------------------------------------------------------------
    // Model Resolvers
    // -------------------------------------------------------------------------

    /** @return class-string<Language> */
    private function languageModel(): string
    {
        return config('translator.models.language', Language::class);
    }

    /** @return class-string<Group> */
    private function groupModel(): string
    {
        return config('translator.models.group', Group::class);
    }

    /** @return class-string<ExportLog> */
    private function exportLogModel(): string
    {
        return config('translator.models.export_log', ExportLog::class);
    }
}
