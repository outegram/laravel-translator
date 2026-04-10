<?php

declare(strict_types=1);

namespace Syriable\Translator\Services\Exporter;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Syriable\Translator\Contracts\TranslationExporterContract;
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
 * ### Dry-run mode
 *
 * When ExportOptions::$dryRun is true, no files are written to disk. The
 * returned ExportResult carries the same counters (locales, files, keys) and
 * a wouldWritePaths list of the absolute paths that would have been written.
 * ExportCompleted is NOT dispatched and no ExportLog is recorded in dry-run mode.
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
final readonly class TranslationExporter implements TranslationExporterContract
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
     * In dry-run mode, no files are written. The result still carries accurate
     * counts and the list of paths that would have been written.
     *
     * @param  ExportOptions  $options  Typed configuration for this export run.
     * @return ExportResult Immutable summary of the completed (or previewed) export.
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

        // In dry-run mode: do not log, do not dispatch the event.
        if ($options->dryRun) {
            return $result;
        }

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
     * @return Collection<int, Language>
     */
    private function resolveLanguages(?string $locale): Collection
    {
        return $this->languageModel()::query()
            ->active()
            ->when($locale, static fn (Builder $q) => $q->where('code', $locale))
            ->get();
    }

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

        $filePath = $group->isJson()
            ? $this->buildJsonPath($langPath, $language->code)
            : $this->buildPhpPath($langPath, $language->code, $group);

        if ($options->dryRun) {
            // In dry-run mode record the path but skip the actual write.
            return new ExportResult(
                keyCount: count($translations),
                fileCount: 1,
                dryRun: true,
                wouldWritePaths: [$filePath],
            );
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

    private function buildJsonPath(string $langPath, string $localeCode): string
    {
        return $langPath.DIRECTORY_SEPARATOR.$localeCode.'.json';
    }

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
     * @return array<string, string>
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

    private function recordExportLog(ExportResult $result, ExportOptions $options): ExportLog
    {
        return $this->exportLogModel()::query()->create([
            'locale_count' => $result->localeCount,
            'file_count'   => $result->fileCount,
            'key_count'    => $result->keyCount,
            'duration_ms'  => $result->durationMs,
            'triggered_by' => $options->triggeredBy,
            'source'       => $options->source,
        ]);
    }

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