<?php

declare(strict_types=1);

namespace Syriable\Translator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Syriable\Translator\Console\Concerns\DisplayHelper;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\Jobs\TranslateKeysJob;
use Syriable\Translator\Models\Language;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Diagnostic command that verifies the queue is correctly configured for AI
 * translation jobs and optionally dispatches a test job to confirm end-to-end
 * storage.
 *
 * Usage:
 *   php artisan translator:queue-check
 *   php artisan translator:queue-check --dispatch-test
 */
final class QueueDiagnosticCommand extends Command
{
    use DisplayHelper;

    protected $signature = 'translator:queue-check
        {--dispatch-test : Dispatch a test job to verify it actually reaches the queue}';

    protected $description = 'Diagnose the queue configuration for AI translation jobs';

    public function handle(): int
    {
        $this->displayHeader('Queue Diagnostic');

        $allPassed = true;

        $allPassed = $this->checkQueueConnection() && $allPassed;
        $allPassed = $this->checkJobsTable() && $allPassed;
        $allPassed = $this->checkJobSerialization() && $allPassed;

        if ($allPassed && $this->option('dispatch-test')) {
            $allPassed = $this->dispatchTestJob() && $allPassed;
        }

        $this->newLine();

        if ($allPassed) {
            info('✅ All checks passed. Run the worker to process jobs:');
            $this->newLine();
            $queueName = config('translator.ai.queue', 'default');
            $this->line("    <comment>php artisan queue:work --queue={$queueName}</comment>");
        } else {
            error('Some checks failed. Fix the issues above before using --queue.');
        }

        $this->newLine();

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    // -------------------------------------------------------------------------
    // Checks
    // -------------------------------------------------------------------------

    private function checkQueueConnection(): bool
    {
        $connectionName = config('queue.default', 'sync');
        $driver = config("queue.connections.{$connectionName}.driver", 'sync');

        $this->line("<comment>Queue connection:</comment> {$connectionName} (driver: {$driver})");

        if ($driver === 'sync') {
            $this->newLine();
            error('  ✗ QUEUE_CONNECTION is "sync".');
            $this->line('    Jobs execute immediately inline and are never stored.');
            $this->line('    Fix: set QUEUE_CONNECTION=database in .env, then run:');
            $this->line('      php artisan config:clear');
            $this->newLine();

            return false;
        }

        info('  ✓ Queue connection is not sync.');

        return true;
    }

    private function checkJobsTable(): bool
    {
        $connectionName = config('queue.default');
        $driver = config("queue.connections.{$connectionName}.driver");

        if ($driver !== 'database') {
            info("  ✓ Driver is [{$driver}] — no jobs table required.");

            return true;
        }

        $this->line('<comment>Jobs table:</comment>');

        if (! Schema::hasTable('jobs')) {
            $this->newLine();
            error('  ✗ The `jobs` table does not exist.');
            $this->line('    Fix:');
            $this->line('      php artisan queue:table');
            $this->line('      php artisan migrate');
            $this->newLine();

            return false;
        }

        $pending = DB::table('jobs')->count();
        info("  ✓ `jobs` table exists. Pending jobs: {$pending}");

        $failed = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->count()
            : 0;

        if ($failed > 0) {
            warning("  ⚠  {$failed} failed job(s) in `failed_jobs`. Check: php artisan queue:failed");
        }

        return true;
    }

    private function checkJobSerialization(): bool
    {
        $this->line('<comment>Job serialization:</comment>');

        $language = Language::query()->first();

        if ($language === null) {
            warning('  ⚠  No Language records found — skipping serialization check.');
            warning('     Run translator:import first to populate languages.');

            return true;
        }

        $job = new TranslateKeysJob(
            request: new TranslationRequest(
                sourceLanguage: 'en',
                targetLanguage: 'ar',
                keys: ['test.key' => 'Test value.'],
                groupName: '__diagnostic',
            ),
            language: $language,
            provider: null,
        );

        try {
            $serialized = serialize($job);
            $unserialized = unserialize($serialized);

            if (! $unserialized instanceof TranslateKeysJob) {
                error('  ✗ Job deserialized to unexpected type.');

                return false;
            }

            if ($unserialized->languageId !== $language->id) {
                error('  ✗ Language ID did not survive serialization.');

                return false;
            }

            if (($unserialized->requestData['targetLanguage'] ?? null) !== 'ar') {
                error('  ✗ Request data did not survive serialization.');

                return false;
            }

            info('  ✓ Job serializes and deserializes correctly.');

            return true;
        } catch (Throwable $e) {
            error('  ✗ Serialization failed: '.$e->getMessage());
            $this->line('    This is the root cause of jobs not appearing in the queue.');

            return false;
        }
    }

    private function dispatchTestJob(): bool
    {
        $this->line('<comment>Test dispatch:</comment>');

        $language = Language::query()->first();

        if ($language === null) {
            warning('  ⚠  No Language records found — skipping test dispatch.');

            return true;
        }

        $beforeCount = DB::table('jobs')->count();

        TranslateKeysJob::dispatch(
            new TranslationRequest(
                sourceLanguage: 'en',
                targetLanguage: 'ar',
                keys: ['__diagnostic.key' => 'Diagnostic test — safe to delete.'],
                groupName: '__diagnostic',
            ),
            $language,
        )->onQueue(config('translator.ai.queue', 'default'));

        $afterCount = DB::table('jobs')->count();

        if ($afterCount <= $beforeCount) {
            error('  ✗ Job was dispatched but did not appear in the jobs table.');

            return false;
        }

        info("  ✓ Test job stored in the jobs table (total pending: {$afterCount}).");
        warning('  Note: the worker will discard this job safely (no matching translation keys).');

        return true;
    }
}
