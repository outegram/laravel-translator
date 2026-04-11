<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Artisan command that displays translation coverage statistics.
 *
 * Shows what percentage of translation keys have been translated and reviewed
 * for each active language. Can be filtered by locale or group, and provides
 * machine-readable output in JSON or CSV format for CI/CD pipelines.
 *
 * Usage:
 * ```bash
 * php artisan translator:coverage
 * php artisan translator:coverage --locale=ar
 * php artisan translator:coverage --group=auth
 * php artisan translator:coverage --min=80          # warn on < 80%
 * php artisan translator:coverage --format=json
 * php artisan translator:coverage --format=csv
 * php artisan translator:coverage --fail-below=90   # exit 1 if any locale < 90%
 * ```
 */
final class CoverageCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:coverage
        {--locale=       : Filter coverage report to a specific locale code}
        {--group=        : Filter coverage report to a specific group name}
        {--min=          : Highlight languages below this coverage percentage}
        {--fail-below=   : Exit with code 1 if any language falls below this percentage}
        {--format=table  : Output format: table (default), json, csv}';

    protected $description = 'Show translation coverage statistics per language and group';

    public function handle(): int
    {
        $this->displayHeader('Coverage');

        $localeFilter = $this->option('locale') ?: null;
        $groupFilter = $this->option('group') ?: null;
        $minPercent = $this->option('min') !== null ? (float) $this->option('min') : null;
        $failBelow = $this->option('fail-below') !== null ? (float) $this->option('fail-below') : null;
        $format = (string) ($this->option('format') ?: 'table');

        $totalKeys = $this->resolveTotalKeyCount($groupFilter);

        if ($totalKeys === 0) {
            info('No translation keys found. Run translator:import first.');

            return self::SUCCESS;
        }

        $languages = Language::query()
            ->active()
            ->when($localeFilter, fn ($q) => $q->where('code', $localeFilter))
            ->orderBy('code')
            ->get();

        if ($languages->isEmpty()) {
            info('No active languages found matching the filter.');

            return self::SUCCESS;
        }

        $rows = $this->buildCoverageRows($languages, $totalKeys, $groupFilter);

        match ($format) {
            'json' => $this->outputJson($rows),
            'csv' => $this->outputCsv($rows),
            default => $this->outputTable($rows, $totalKeys, $minPercent),
        };

        return $this->evaluateExitCode($rows, $failBelow);
    }

    // -------------------------------------------------------------------------
    // Data Collection
    // -------------------------------------------------------------------------

    private function resolveTotalKeyCount(?string $groupFilter): int
    {
        return TranslationKey::query()
            ->when($groupFilter, fn ($q) => $q->whereHas('group', fn ($q) => $q->where('name', $groupFilter)))
            ->count();
    }

    /**
     * @param  Collection<int, Language>  $languages
     * @return array<int, array<string, mixed>>
     */
    private function buildCoverageRows(Collection $languages, int $totalKeys, ?string $groupFilter): array
    {
        $rows = [];

        foreach ($languages as $language) {
            $query = Translation::query()
                ->where('language_id', $language->id)
                ->when(
                    $groupFilter,
                    fn ($q) => $q->whereHas('translationKey.group', fn ($q) => $q->where('name', $groupFilter)),
                );

            $translatedCount = (clone $query)
                ->whereIn('status', [TranslationStatus::Translated->value, TranslationStatus::Reviewed->value])
                ->whereNotNull('value')
                ->count();

            $reviewedCount = (clone $query)
                ->where('status', TranslationStatus::Reviewed->value)
                ->whereNotNull('value')
                ->count();

            $translatedPercent = $totalKeys > 0
                ? round(($translatedCount / $totalKeys) * 100, 1)
                : 0.0;

            $reviewedPercent = $totalKeys > 0
                ? round(($reviewedCount / $totalKeys) * 100, 1)
                : 0.0;

            $rows[] = [
                'locale' => $language->code,
                'name' => $language->name,
                'source' => $language->is_source,
                'total_keys' => $totalKeys,
                'translated_count' => $translatedCount,
                'reviewed_count' => $reviewedCount,
                'translated_percent' => $translatedPercent,
                'reviewed_percent' => $reviewedPercent,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['translated_percent'] <=> $a['translated_percent']);

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Output Formatters
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function outputTable(array $rows, int $totalKeys, ?float $minPercent): void
    {
        $this->newLine();
        info('Total translation keys: '.number_format($totalKeys));
        $this->newLine();

        $tableRows = array_map(function (array $row) use ($minPercent): array {
            $translatedLabel = $row['translated_percent'].'%';
            $reviewedLabel = $row['reviewed_percent'].'%';

            if ($minPercent !== null && (float) $row['translated_percent'] < $minPercent) {
                $translatedLabel = "⚠  {$translatedLabel}";
            }

            $sourceLabel = $row['source'] ? ' (source)' : '';

            return [
                $row['locale'].$sourceLabel,
                $row['name'],
                number_format((int) $row['translated_count']).'/'.number_format((int) $row['total_keys']),
                $translatedLabel,
                number_format((int) $row['reviewed_count']).'/'.number_format((int) $row['total_keys']),
                $reviewedLabel,
            ];
        }, $rows);

        $this->table(
            headers: ['Locale', 'Language', 'Translated', 'Translated %', 'Reviewed', 'Reviewed %'],
            rows: $tableRows,
        );

        if ($minPercent !== null) {
            $below = array_filter($rows, fn (array $r): bool => (float) $r['translated_percent'] < $minPercent);

            if (! empty($below)) {
                $this->newLine();
                warning(count($below).' language(s) are below the '.number_format($minPercent, 1).'% threshold.');
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function outputJson(array $rows): void
    {
        $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function outputCsv(array $rows): void
    {
        $this->line('locale,name,total_keys,translated_count,translated_percent,reviewed_count,reviewed_percent');

        foreach ($rows as $row) {
            $this->line(implode(',', [
                $row['locale'],
                "\"{$row['name']}\"",
                $row['total_keys'],
                $row['translated_count'],
                $row['translated_percent'],
                $row['reviewed_count'],
                $row['reviewed_percent'],
            ]));
        }
    }

    // -------------------------------------------------------------------------
    // Exit Code
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function evaluateExitCode(array $rows, ?float $failBelow): int
    {
        if ($failBelow === null) {
            return self::SUCCESS;
        }

        $failing = array_filter(
            $rows,
            fn (array $r): bool => ! $r['source'] && (float) $r['translated_percent'] < $failBelow,
        );

        if (! empty($failing)) {
            $locales = implode(', ', array_column($failing, 'locale'));
            warning("Coverage below {$failBelow}% for: {$locales}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
