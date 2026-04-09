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

/**
 * Artisan command that exports translation records from the database back to
 * language files on disk.
 *
 * Writes PHP group files and JSON locale files to the configured lang directory,
 * preserving the original Laravel file structure including vendor-namespaced paths.
 *
 * Usage:
 * ```bash
 * php artisan translator:export
 * php artisan translator:export --locale=ar
 * php artisan translator:export --group=auth
 * php artisan translator:export --locale=ar --group=auth
 * php artisan translator:export --no-interaction
 * ```
 */
final class ExportCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:export
        {--locale= : Export only a specific locale code (e.g. ar, fr)}
        {--group=  : Export only a specific translation group (e.g. auth, validation)}';

    protected $description = 'Export translations from the database to language files on disk';

    /**
     * Execute the export command.
     *
     * @param  TranslationExporter  $exporter  Injected by Laravel's command IoC resolution.
     */
    public function handle(TranslationExporter $exporter): int
    {
        $this->displayHeader('Export');

        $options = $this->resolveExportOptions();
        $result = $this->runExport($exporter, $options);

        $this->displaySummary($result);

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Option Resolution
    // -------------------------------------------------------------------------

    /**
     * Build a typed ExportOptions DTO from the command's flags and application config.
     */
    private function resolveExportOptions(): ExportOptions
    {
        return ExportOptions::fromConfig([
            'locale' => $this->option('locale') ?: null,
            'group' => $this->option('group') ?: null,
            'source' => 'cli',
            'triggered_by' => $this->resolveTriggeredBy(),
        ]);
    }

    /**
     * Resolve the identity of the process triggering this export.
     */
    private function resolveTriggeredBy(): string
    {
        return get_current_user() ?: 'cli';
    }

    // -------------------------------------------------------------------------
    // Export Execution
    // -------------------------------------------------------------------------

    /**
     * Execute the export, wrapping it in a spinner for interactive terminals.
     */
    private function runExport(TranslationExporter $exporter, ExportOptions $options): ExportResult
    {
        $callback = static fn (): ExportResult => $exporter->export($options);

        if ($this->input->isInteractive()) {
            return spin(
                callback: $callback,
                message: 'Exporting translations...',
            );
        }

        return $callback();
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /**
     * Render a summary table and completion message after a successful export.
     */
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
}
