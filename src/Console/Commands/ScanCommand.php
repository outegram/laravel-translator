<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\DTOs\ScanResult;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\TranslationKey;
use Syriable\Translator\Services\Scanner\TranslationKeyScanner;
use Syriable\Translator\Services\TranslationKeyReplicator;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Artisan command that scans application source files for translation key calls
 * and compares them against the TranslationKey records in the database.
 *
 * Reports two categories of divergence:
 *  - Missing keys:   Keys called in source code (via __(), trans(), etc.) that
 *                    have no corresponding TranslationKey record in the database.
 *  - Orphaned keys:  TranslationKey records in the database that are not called
 *                    anywhere in the scanned source files.
 *
 * Usage:
 * ```bash
 * # Full report — missing + orphaned
 * php artisan translator:scan
 *
 * # CI gate — exits with code 1 if missing keys exist
 * php artisan translator:scan --fail-on-missing
 *
 * # Show only missing keys (useful for focused debugging)
 * php artisan translator:scan --missing-only
 *
 * # Show only orphaned keys (useful for cleanup review)
 * php artisan translator:scan --orphans-only
 *
 * # Insert missing keys into the database
 * php artisan translator:scan --sync
 *
 * # Remove orphaned keys from the database (requires confirmation)
 * php artisan translator:scan --purge-orphans
 * ```
 *
 * Scanning is configured via config('translator.scanner.*').
 */
final class ScanCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:scan
        {--missing-only    : Only report keys present in code but absent from the database}
        {--orphans-only    : Only report keys present in the database but absent from code}
        {--fail-on-missing : Exit with code 1 when missing keys are found (for CI pipelines)}
        {--sync            : Insert all missing keys into the database}
        {--purge-orphans   : Remove all orphaned keys from the database (requires confirmation)}';

    protected $description = 'Scan source files for translation key calls and compare against the database';

    public function handle(
        TranslationKeyScanner $scanner,
        TranslationKeyReplicator $replicator,
    ): int {
        $this->displayHeader('Scan');

        $result = $this->runScan($scanner);

        $this->displayScanSummary($result);

        if (! $this->option('orphans-only')) {
            $this->displayMissingKeys($result);
        }

        if (! $this->option('missing-only')) {
            $this->displayOrphanedKeys($result);
        }

        if ($result->isClean()) {
            info('✅ No missing or orphaned keys found.');

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        if ($this->option('sync') && $result->hasMissingKeys()) {
            $exitCode = $this->syncMissingKeys($result, $replicator);
        }

        if ($this->option('purge-orphans') && $result->hasOrphanedKeys()) {
            $this->purgeOrphanedKeys($result);
        }

        if ($this->option('fail-on-missing') && $result->hasMissingKeys()) {
            return self::FAILURE;
        }

        return $exitCode;
    }

    // -------------------------------------------------------------------------
    // Scan execution
    // -------------------------------------------------------------------------

    private function runScan(TranslationKeyScanner $scanner): ScanResult
    {
        if ($this->input->isInteractive()) {
            return spin(
                callback: static fn (): ScanResult => $scanner->scan(),
                message: 'Scanning source files...',
            );
        }

        return $scanner->scan();
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    private function displayScanSummary(ScanResult $result): void
    {
        $this->newLine();
        info('Scan completed in '.$this->formatDuration($result->durationMs));

        $this->table(
            headers: ['Metric', 'Value'],
            rows: [
                ['Files scanned',       number_format($result->fileCount)],
                ['Keys found in code',  number_format($result->usedKeyCount())],
                ['Missing from DB',     number_format($result->missingKeyCount())],
                ['Orphaned in DB',      number_format($result->orphanedKeyCount())],
            ],
        );
    }

    private function displayMissingKeys(ScanResult $result): void
    {
        if (! $result->hasMissingKeys()) {
            return;
        }

        $this->newLine();
        warning('⚠️  Missing keys — present in code, absent from database:');

        $this->table(
            headers: ['#', 'Key'],
            rows: array_map(
                static fn (int $i, string $k): array => [$i + 1, $k],
                array_keys($result->missingKeys),
                $result->missingKeys,
            ),
        );

        $this->line('  Tip: run <comment>translator:import</comment> to import from lang files,');
        $this->line('  or use <comment>--sync</comment> to create empty placeholder records.');
    }

    private function displayOrphanedKeys(ScanResult $result): void
    {
        if (! $result->hasOrphanedKeys()) {
            return;
        }

        $this->newLine();
        warning('⚠️  Orphaned keys — present in database, absent from scanned code:');

        $this->table(
            headers: ['#', 'Key'],
            rows: array_map(
                static fn (int $i, string $k): array => [$i + 1, $k],
                array_keys($result->orphanedKeys),
                $result->orphanedKeys,
            ),
        );

        $this->line('  Note: vendor packages and programmatically-built keys may appear orphaned.');
        $this->line('  Review carefully before using <comment>--purge-orphans</comment>.');
    }

    // -------------------------------------------------------------------------
    // --sync: insert missing keys
    // -------------------------------------------------------------------------

    /**
     * Insert all missing TranslationKey records into the database.
     *
     * For each missing key:
     *  1. Parse the group name and key string from the qualified key.
     *  2. Find or create the Group record.
     *  3. Create the TranslationKey record.
     *  4. Replicate the new key to all active languages (creates untranslated rows).
     *
     * @return int Command exit code.
     */
    private function syncMissingKeys(ScanResult $result, TranslationKeyReplicator $replicator): int
    {
        $this->newLine();
        info('Syncing '.$result->missingKeyCount().' missing key(s) to the database...');

        $scanner = app(TranslationKeyScanner::class);
        $inserted = 0;
        $failed = 0;

        DB::beginTransaction();

        try {
            foreach ($result->missingKeys as $qualifiedKey) {
                [$groupName, $keyString] = $scanner->parseKeyComponents($qualifiedKey);

                $group = $this->findOrCreateGroup($groupName);
                $translationKey = $this->findOrCreateTranslationKey($group, $keyString);

                if ($translationKey->wasRecentlyCreated) {
                    $replicator->replicateSingleKey($translationKey);
                    $inserted++;
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        info("✅ Inserted {$inserted} key(s). Run <comment>translator:import</comment> to populate values from lang files.");

        if ($failed > 0) {
            warning("{$failed} key(s) could not be inserted. Check the output above.");
        }

        return self::SUCCESS;
    }

    /**
     * Find or create a Group record by name.
     *
     * New groups default to PHP format with no namespace. JSON group uses the
     * JSON format constant. The file_path is left null — it will be populated
     * when the file is created on disk and imported.
     */
    private function findOrCreateGroup(string $groupName): Group
    {
        /** @var class-string<Group> $model */
        $model = config('translator.models.group', Group::class);

        /** @var Group $group */
        $group = $model::query()->firstOrCreate(
            ['name' => $groupName, 'namespace' => null],
            [
                'file_format' => $groupName === Group::JSON_GROUP_NAME
                    ? Group::FORMAT_JSON
                    : Group::FORMAT_PHP,
                'file_path' => null,
            ],
        );

        return $group;
    }

    /**
     * Find or create a TranslationKey record within the given group.
     */
    private function findOrCreateTranslationKey(Group $group, string $key): TranslationKey
    {
        /** @var class-string<TranslationKey> $model */
        $model = config('translator.models.translation_key', TranslationKey::class);

        /** @var TranslationKey $translationKey */
        $translationKey = $model::query()->firstOrCreate(
            ['group_id' => $group->id, 'key' => $key],
            ['parameters' => null, 'is_html' => false, 'is_plural' => false],
        );

        return $translationKey;
    }

    // -------------------------------------------------------------------------
    // --purge-orphans: remove orphaned database keys
    // -------------------------------------------------------------------------

    /**
     * Delete all orphaned TranslationKey records from the database.
     *
     * Requires explicit confirmation in interactive mode.
     * In non-interactive mode (CI), the operation proceeds without prompting
     * when --purge-orphans is combined with --no-interaction.
     *
     * Cascade deletes on the database schema mean all Translation rows for
     * orphaned keys are removed automatically.
     */
    private function purgeOrphanedKeys(ScanResult $result): void
    {
        $this->newLine();

        if ($this->input->isInteractive()) {
            warning('This will permanently delete '.$result->orphanedKeyCount().' key(s) and ALL their translations from the database.');

            if (! confirm(label: 'Proceed with purge?', default: false)) {
                info('Purge cancelled.');

                return;
            }
        }

        $deletedCount = 0;

        DB::beginTransaction();

        try {
            foreach ($result->orphanedKeys as $qualifiedKey) {
                $scanner = app(TranslationKeyScanner::class);
                [$groupName, $keyString] = $scanner->parseKeyComponents($qualifiedKey);

                /** @var class-string<Group> $groupModel */
                $groupModel = config('translator.models.group', Group::class);

                /** @var Group|null $group */
                $group = $groupModel::query()
                    ->whereNull('namespace')
                    ->where('name', $groupName)
                    ->first();

                if ($group === null) {
                    continue;
                }

                /** @var class-string<TranslationKey> $keyModel */
                $keyModel = config('translator.models.translation_key', TranslationKey::class);

                $deleted = $keyModel::query()
                    ->where('group_id', $group->id)
                    ->where('key', $keyString)
                    ->delete();

                $deletedCount += (int) $deleted;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            error('Purge failed: '.$e->getMessage());

            return;
        }

        info("✅ Purged {$deletedCount} orphaned key(s) from the database.");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function formatDuration(int $milliseconds): string
    {
        return $milliseconds >= 1000
            ? round($milliseconds / 1000, 2).'s'
            : $milliseconds.'ms';
    }
}