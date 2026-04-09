<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Console\Command;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\DTOs\ImportOptions;
use Syriable\Translator\DTOs\ImportResult;
use Syriable\Translator\Services\Importer\TranslationImporter;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Artisan command that imports translation files from disk into the database.
 *
 * Reads PHP group files, JSON locale files, and vendor-namespaced files from
 * the configured lang directory and persists them via TranslationImporter.
 *
 * Options:
 *  --fresh          Purge all existing translation data before importing.
 *  --no-overwrite   Skip updating existing translation values (insert-only).
 *
 * Usage:
 * ```bash
 * php artisan translator:import
 * php artisan translator:import --fresh
 * php artisan translator:import --no-overwrite
 * php artisan translator:import --fresh --no-interaction
 * ```
 */
final class ImportCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:import
        {--fresh        : Purge all existing translations before importing}
        {--no-overwrite : Skip updating existing translation values (insert-only)}';

    protected $description = 'Import translation files from disk into the database';

    /**
     * Execute the import command.
     *
     * Resolves runtime options into an ImportOptions DTO, optionally confirms
     * a destructive fresh import with the user, runs the importer (with a
     * spinner in interactive mode), and renders a summary table on completion.
     *
     * @param  TranslationImporter  $importer  Injected by Laravel's command IoC resolution.
     * @return int Command exit code (self::SUCCESS or self::FAILURE).
     */
    public function handle(TranslationImporter $importer): int
    {
        $this->displayHeader('Import');

        $options = $this->resolveImportOptions();

        if ($options->fresh && ! $this->confirmFreshImport()) {
            info('Import cancelled.');

            return self::SUCCESS;
        }

        $result = $this->runImport($importer, $options);

        $this->displaySummary($result);

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Option Resolution
    // -------------------------------------------------------------------------

    /**
     * Build a typed ImportOptions DTO from the command's flags and application config.
     *
     * Config-level detection settings (parameters, HTML, plural, vendor scan)
     * are read via ImportOptions::fromConfig() and can be overridden here as
     * needed when the command provides dedicated flags for them in future.
     */
    private function resolveImportOptions(): ImportOptions
    {
        return ImportOptions::fromConfig([
            'fresh' => (bool) $this->option('fresh'),
            'overwrite' => ! $this->option('no-overwrite'),
            'source' => 'cli',
            'triggered_by' => $this->resolveTriggeredBy(),
        ]);
    }

    /**
     * Resolve the identity of the process triggering this import.
     *
     * Uses the current system user when running in a console context.
     * Falls back gracefully when the user cannot be determined.
     */
    private function resolveTriggeredBy(): string
    {
        return get_current_user() ?: 'cli';
    }

    // -------------------------------------------------------------------------
    // Confirmation
    // -------------------------------------------------------------------------

    /**
     * Request confirmation before executing a destructive fresh import.
     *
     * Skipped entirely in non-interactive mode (e.g. CI pipelines, scheduled
     * jobs), where the --fresh flag is treated as an unconditional instruction.
     *
     * @return bool True when the import should proceed; false to abort.
     */
    private function confirmFreshImport(): bool
    {
        if ($this->option('no-interaction')) {
            return true;
        }

        warning('This will delete ALL existing translations and re-import from disk.');

        return confirm(
            label: 'Are you sure you want to continue?',
            default: true,
        );
    }

    // -------------------------------------------------------------------------
    // Import Execution
    // -------------------------------------------------------------------------

    /**
     * Execute the import, wrapping it in a spinner for interactive terminals.
     *
     * Non-interactive sessions (CI, cron) run the import directly without
     * the spinner to avoid polluting log output with ANSI escape sequences.
     */
    private function runImport(TranslationImporter $importer, ImportOptions $options): ImportResult
    {
        $callback = static fn (): ImportResult => $importer->import($options);

        if ($this->input->isInteractive()) {
            return spin(
                callback: $callback,
                message: 'Importing translations...',
            );
        }

        return $callback();
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /**
     * Render a summary table and completion message after a successful import.
     *
     * @param  ImportResult  $result  The immutable result returned by the importer.
     */
    private function displaySummary(ImportResult $result): void
    {
        info('Import completed in '.$this->formatDuration($result->durationMs));

        $this->table(
            headers: ['Metric', 'Value'],
            rows: [
                ['Locales processed',  $result->localeCount],
                ['Total keys',         $result->keyCount],
                ['New keys inserted',  $result->insertedCount],
                ['Existing updated',   $result->updatedCount],
                ['Duration',           $this->formatDuration($result->durationMs)],
            ],
        );
    }

    /**
     * Format a millisecond duration as a human-readable string.
     *
     * Returns seconds for durations >= 1000ms (e.g. `'1.24s'`),
     * and milliseconds for shorter runs (e.g. `'340ms'`).
     *
     * @param  int  $milliseconds  Elapsed time in milliseconds.
     */
    private function formatDuration(int $milliseconds): string
    {
        return $milliseconds >= 1000
            ? round($milliseconds / 1000, 2).'s'
            : $milliseconds.'ms';
    }
}
