<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Console\Command;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\Models\AITranslationLog;
use Syriable\Translator\Models\ExportLog;
use Syriable\Translator\Models\ImportLog;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Artisan command that removes stale import, export, and AI translation log records.
 *
 * The retention window is read from `config('translator.log_retention_days')` (default 90).
 * Override it per-run with `--days=`.
 *
 * Use `--dry-run` to preview how many records would be deleted without touching data —
 * useful for auditing before a first scheduled run.
 *
 * The package registers this command with the Laravel scheduler automatically:
 *
 *   $schedule->command('translator:prune-logs')->weekly();
 *
 * You can change the schedule by publishing the service provider or calling the
 * command manually from your own `routes/console.php` schedule definition.
 *
 * Usage:
 * ```bash
 * # Delete logs older than the configured retention window
 * php artisan translator:prune-logs
 *
 * # Use a custom window (overrides log_retention_days from config)
 * php artisan translator:prune-logs --days=30
 *
 * # Preview what would be deleted without deleting anything
 * php artisan translator:prune-logs --dry-run
 *
 * # Preview with a custom window
 * php artisan translator:prune-logs --days=30 --dry-run
 * ```
 */
final class PruneLogsCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:prune-logs
        {--days=     : Retention window in days (overrides log_retention_days from config)}
        {--dry-run   : Preview deletions without modifying the database}';

    protected $description = 'Delete import, export, and AI translation log records older than the retention window';

    public function handle(): int
    {
        $this->displayHeader('Prune Logs');

        $days = $this->resolveDays();

        if ($days <= 0) {
            info('Log retention is disabled (log_retention_days = 0). Nothing to prune.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            warning('Dry run — no records will be deleted.');
        }

        $counts = $this->resolveCounts($cutoff);

        $this->displayCountTable($counts, $days, $cutoff->toDateString(), $isDryRun);

        $total = array_sum($counts);

        if ($total === 0) {
            info('No log records found outside the retention window. Nothing to prune.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            info("Dry run complete. Re-run without --dry-run to delete {$total} record(s).");

            return self::SUCCESS;
        }

        $this->deleteRecords($cutoff);

        info("✅ Pruned {$total} log record(s) older than {$days} days.");

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Counts
    // -------------------------------------------------------------------------

    /**
     * @return array{import: int, export: int, ai: int}
     */
    private function resolveCounts(\Illuminate\Support\Carbon $cutoff): array
    {
        return [
            'import' => ImportLog::query()->where('created_at', '<', $cutoff)->count(),
            'export' => ExportLog::query()->where('created_at', '<', $cutoff)->count(),
            'ai'     => AITranslationLog::query()->where('created_at', '<', $cutoff)->count(),
        ];
    }

    // -------------------------------------------------------------------------
    // Deletion
    // -------------------------------------------------------------------------

    private function deleteRecords(\Illuminate\Support\Carbon $cutoff): void
    {
        ImportLog::query()->where('created_at', '<', $cutoff)->delete();
        ExportLog::query()->where('created_at', '<', $cutoff)->delete();
        AITranslationLog::query()->where('created_at', '<', $cutoff)->delete();
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /**
     * @param  array{import: int, export: int, ai: int}  $counts
     */
    private function displayCountTable(
        array $counts,
        int $days,
        string $cutoffDate,
        bool $isDryRun,
    ): void {
        $label = $isDryRun ? 'Records that would be deleted' : 'Records to delete';

        $this->newLine();
        info("Retention window: {$days} days (records before {$cutoffDate})");

        $this->table(
            headers: ['Log table', $label],
            rows: [
                ['Import logs',         number_format($counts['import'])],
                ['Export logs',         number_format($counts['export'])],
                ['AI translation logs', number_format($counts['ai'])],
                ['Total',               number_format(array_sum($counts))],
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Configuration helpers
    // -------------------------------------------------------------------------

    private function resolveDays(): int
    {
        $option = $this->option('days');

        if ($option !== null) {
            return max(0, (int) $option);
        }

        return max(0, (int) config('translator.log_retention_days', 90));
    }
}