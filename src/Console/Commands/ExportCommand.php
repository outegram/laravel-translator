<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Console\Command;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\DTOs\ExportOptions;
use Syriable\Translator\DTOs\ExportResult;
use Syriable\Translator\Services\Exporter\TranslationExporter;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Artisan command that exports translation records from the database back to
 * language files on disk.
 *
 * Writes PHP group files and JSON locale files to the configured lang directory,
 * preserving the original Laravel file structure including vendor-namespaced paths.
 *
 * ### Dry-run mode
 *
 * Pass `--dry-run` to preview which files would be written without touching disk.
 * The output lists every file path that would be created or overwritten, along with
 * the key and locale counts — useful for auditing before a first export or before
 * exporting to a production environment.
 *
 * Usage:
 * ```bash
 * php artisan translator:export
 * php artisan translator:export --locale=ar
 * php artisan translator:export --group=auth
 * php artisan translator:export --locale=ar --group=auth
 * php artisan translator:export --dry-run
 * php artisan translator:export --locale=fr --dry-run
 * ```
 */
final class ExportCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:export
        {--locale=   : Export only a specific locale code (e.g. ar, fr)}
        {--group=    : Export only a specific translation group (e.g. auth, validation)}
        {--dry-run   : Preview which files would be written without writing anything}';

    protected $description = 'Export translations from the database to language files on disk';

    /**
     * @param  TranslationExporter  $exporter  Injected by Laravel's command IoC resolution.
     */
    public function handle(TranslationExporter $exporter): int
    {
        $this->displayHeader('Export');

        $options = $this->resolveExportOptions();
        $result = $this->runExport($exporter, $options);

        if ($options->dryRun) {
            $this->displayDryRunSummary($result);
        } else {
            $this->displaySummary($result);
        }

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Option Resolution
    // -------------------------------------------------------------------------

    private function resolveExportOptions(): ExportOptions
    {
        return ExportOptions::fromConfig([
            'locale'      => $this->option('locale') ?: null,
            'group'       => $this->option('group') ?: null,
            'dry_run'     => (bool) $this->option('dry-run'),
            'source'      => 'cli',
            'triggered_by'=> $this->resolveTriggeredBy(),
        ]);
    }

    private function resolveTriggeredBy(): string
    {
        return get_current_user() ?: 'cli';
    }

    // -------------------------------------------------------------------------
    // Export Execution
    // -------------------------------------------------------------------------

    private function runExport(TranslationExporter $exporter, ExportOptions $options): ExportResult
    {
        $message = $options->dryRun ? 'Previewing export...' : 'Exporting translations...';
        $callback = static fn (): ExportResult => $exporter->export($options);

        if ($this->input->isInteractive()) {
            return spin(callback: $callback, message: $message);
        }

        return $callback();
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    private function displaySummary(ExportResult $result): void
    {
        info('Export completed in '.$result->formattedDuration());

        $this->table(
            headers: ['Metric', 'Value'],
            rows: [
                ['Locales processed', $result->localeCount],
                ['Files written',     $result->fileCount],
                ['Keys exported',     $result->keyCount],
                ['Duration',          $result->formattedDuration()],
            ],
        );
    }

    private function displayDryRunSummary(ExportResult $result): void
    {
        warning('Dry run — no files were written.');
        $this->newLine();

        info('Files that would be written:');

        if (empty($result->wouldWritePaths)) {
            info('  (none — no qualifying translations found)');

            return;
        }

        $this->table(
            headers: ['#', 'File path'],
            rows: array_map(
                static fn (int $i, string $path): array => [$i + 1, $path],
                array_keys($result->wouldWritePaths),
                $result->wouldWritePaths,
            ),
        );

        $this->newLine();

        $this->table(
            headers: ['Metric', 'Value'],
            rows: [
                ['Locales', $result->localeCount],
                ['Files',   $result->fileCount],
                ['Keys',    $result->keyCount],
            ],
        );

        $this->newLine();
        $this->line('Re-run without <comment>--dry-run</comment> to write these files.');
    }
}