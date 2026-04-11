<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Console\Command;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Services\Importer\JsonTranslationFileLoader;
use Syriable\Translator\Services\Importer\PhpTranslationFileLoader;
use Syriable\Translator\Services\Importer\TranslationDirectoryExplorer;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Artisan command that compares database translations against on-disk files.
 *
 * This is useful for auditing whether the database and the lang files are in
 * sync after manual edits, partial imports, or deployment discrepancies.
 *
 * Output categories:
 *
 * - DB only:      Key exists in the database but has no corresponding file entry.
 * - File only:    Key exists in a lang file but has no database record.
 * - Value differs: Key exists in both, but the values differ.
 *
 * Usage:
 * ```bash
 * php artisan translator:diff --locale=en
 * php artisan translator:diff --locale=fr --group=auth
 * php artisan translator:diff --locale=ar --show-db-only
 * php artisan translator:diff --locale=en --show-file-only
 * php artisan translator:diff --locale=en --show-changed
 * php artisan translator:diff --locale=en --format=json
 * ```
 */
final class DiffCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:diff
        {--locale=         : Locale code to diff (required)}
        {--group=          : Limit diff to a specific group}
        {--show-db-only    : Show keys present in DB but absent from files}
        {--show-file-only  : Show keys present in files but absent from DB}
        {--show-changed    : Show keys where DB and file values differ}
        {--format=table    : Output format: table (default) or json}';

    protected $description = 'Compare database translations against on-disk lang files';

    public function handle(
        PhpTranslationFileLoader $phpLoader,
        JsonTranslationFileLoader $jsonLoader,
        TranslationDirectoryExplorer $explorer,
    ): int {
        $this->displayHeader('Diff');

        $localeCode = $this->option('locale') ?: null;

        if (blank($localeCode)) {
            error('--locale is required. Example: --locale=en');

            return self::FAILURE;
        }

        /** @var Language|null $language */
        $language = Language::query()->where('code', $localeCode)->first();

        if ($language === null) {
            error("Language [{$localeCode}] not found in the database.");

            return self::FAILURE;
        }

        $langPath = $this->resolveLangPath();
        $groupFilter = $this->option('group') ?: null;

        // ── Load file-based translations ──────────────────────────────────
        $fileTranslations = $this->loadFileTranslations($phpLoader, $jsonLoader, $explorer, $langPath, $localeCode, $groupFilter);

        // ── Load DB translations ──────────────────────────────────────────
        $dbTranslations = $this->loadDbTranslations($language, $groupFilter);

        // ── Compute diff ──────────────────────────────────────────────────
        $dbOnly = array_diff_key($dbTranslations, $fileTranslations);
        $fileOnly = array_diff_key($fileTranslations, $dbTranslations);
        $changed = [];

        foreach (array_intersect_key($dbTranslations, $fileTranslations) as $key => $dbValue) {
            $fileValue = $fileTranslations[$key] ?? '';

            if ($dbValue !== $fileValue) {
                $changed[$key] = ['db' => $dbValue, 'file' => $fileValue];
            }
        }

        $showDbOnly = $this->option('show-db-only');
        $showFileOnly = $this->option('show-file-only');
        $showChanged = $this->option('show-changed');
        $showAll = ! $showDbOnly && ! $showFileOnly && ! $showChanged;

        $format = (string) ($this->option('format') ?: 'table');

        if ($format === 'json') {
            return $this->outputJson($dbOnly, $fileOnly, $changed);
        }

        $this->displaySummary($localeCode, $dbTranslations, $fileTranslations, $dbOnly, $fileOnly, $changed);

        if ($showAll || $showDbOnly) {
            $this->displaySection('DB only (in database, absent from files)', $dbOnly, '📦');
        }

        if ($showAll || $showFileOnly) {
            $this->displaySection('File only (in files, absent from database)', $fileOnly, '📄');
        }

        if ($showAll || $showChanged) {
            $this->displayChangedSection($changed);
        }

        if (empty($dbOnly) && empty($fileOnly) && empty($changed)) {
            info('✅ Database and files are in sync. No differences found.');
        }

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Data Loading
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function loadFileTranslations(
        PhpTranslationFileLoader $phpLoader,
        JsonTranslationFileLoader $jsonLoader,
        TranslationDirectoryExplorer $explorer,
        string $langPath,
        string $locale,
        ?string $groupFilter,
    ): array {
        $translations = [];

        // PHP group files
        $groupFiles = $explorer->discoverGroupFiles($langPath, $locale);

        foreach ($groupFiles as $groupName => $filePath) {
            if ($groupFilter !== null && $groupName !== $groupFilter) {
                continue;
            }

            $loaded = $phpLoader->load($filePath, $langPath);

            foreach ($loaded as $key => $value) {
                if (is_string($value)) {
                    $translations["{$groupName}.{$key}"] = $value;
                }
            }
        }

        // JSON locale file
        if ($groupFilter === null || $groupFilter === '_json') {
            $jsonFile = $langPath.DIRECTORY_SEPARATOR."{$locale}.json";
            $jsonData = $jsonLoader->load($jsonFile);

            foreach ($jsonData as $key => $value) {
                if (is_string($value)) {
                    $translations[$key] = $value;
                }
            }
        }

        return $translations;
    }

    /**
     * @return array<string, string>
     */
    private function loadDbTranslations(Language $language, ?string $groupFilter): array
    {
        $query = Translation::query()
            ->where('language_id', $language->id)
            ->whereNotNull('value')
            ->with(['translationKey.group']);

        if ($groupFilter !== null) {
            $query->whereHas('translationKey.group', fn ($q) => $q->where('name', $groupFilter));
        }

        $translations = [];

        /** @var Translation $translation */
        foreach ($query->cursor() as $translation) {
            $key = $translation->translationKey;
            $group = $key?->group;

            if ($key === null || $group === null || $group->isVendor()) {
                continue;
            }

            $qualifiedKey = $group->name === Group::JSON_GROUP_NAME
                ? $key->key
                : "{$group->name}.{$key->key}";

            $translations[$qualifiedKey] = (string) $translation->value;
        }

        return $translations;
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, string>  $dbOnly
     * @param  array<string, string>  $fileOnly
     * @param  array<string, array<string, string>>  $changed
     */
    private function displaySummary(
        string $locale,
        array $dbTranslations,
        array $fileTranslations,
        array $dbOnly,
        array $fileOnly,
        array $changed,
    ): void {
        $this->newLine();

        $this->table(
            headers: ['Metric', 'Count'],
            rows: [
                ['Locale',           $locale],
                ['Keys in DB',       number_format(count($dbTranslations))],
                ['Keys in files',    number_format(count($fileTranslations))],
                ['DB only',          number_format(count($dbOnly))],
                ['File only',        number_format(count($fileOnly))],
                ['Value differs',    number_format(count($changed))],
            ],
        );
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function displaySection(string $title, array $entries, string $icon): void
    {
        if (empty($entries)) {
            return;
        }

        $this->newLine();
        warning("{$icon}  {$title} (".count($entries).')');

        $this->table(
            headers: ['#', 'Key'],
            rows: array_map(
                static fn (int $i, string $k): array => [$i + 1, $k],
                array_keys(array_values($entries)),
                array_keys($entries),
            ),
        );
    }

    /**
     * @param  array<string, array<string, string>>  $changed
     */
    private function displayChangedSection(array $changed): void
    {
        if (empty($changed)) {
            return;
        }

        $this->newLine();
        warning('🔄  Value differs — DB vs file ('.count($changed).')');

        $rows = [];
        $i = 1;

        foreach ($changed as $key => $values) {
            $dbShort = mb_strlen($values['db']) > 60
                ? mb_substr($values['db'], 0, 57).'...'
                : $values['db'];

            $fileShort = mb_strlen($values['file']) > 60
                ? mb_substr($values['file'], 0, 57).'...'
                : $values['file'];

            $rows[] = [$i++, $key, $dbShort, $fileShort];
        }

        $this->table(
            headers: ['#', 'Key', 'DB value', 'File value'],
            rows: $rows,
        );
    }

    /**
     * @param  array<string, string>  $dbOnly
     * @param  array<string, string>  $fileOnly
     * @param  array<string, array<string, string>>  $changed
     */
    private function outputJson(array $dbOnly, array $fileOnly, array $changed): int
    {
        $output = [
            'db_only' => array_keys($dbOnly),
            'file_only' => array_keys($fileOnly),
            'changed' => $changed,
        ];

        $this->line((string) json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function resolveLangPath(): string
    {
        return (string) (config('translator.lang_path') ?? lang_path());
    }
}
