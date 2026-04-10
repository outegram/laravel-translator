<?php

declare(strict_types=1);

use Syriable\Translator\Models\AITranslationLog;
use Syriable\Translator\Models\ExportLog;
use Syriable\Translator\Models\ImportLog;

describe('translator:prune-logs command', function (): void {

    // -------------------------------------------------------------------------
    // Nothing to prune
    // -------------------------------------------------------------------------

    it('exits with SUCCESS and reports nothing when no logs exist', function (): void {
        $this->artisan('translator:prune-logs')
            ->expectsOutputToContain('Nothing to prune')
            ->assertExitCode(0);
    });

    it('exits with SUCCESS when all logs are within the retention window', function (): void {
        ImportLog::factory()->create(['created_at' => now()->subDays(10)]);

        $this->artisan('translator:prune-logs --days=30')
            ->expectsOutputToContain('Nothing to prune')
            ->assertExitCode(0);
    });

    it('reports disabled when log_retention_days is 0', function (): void {
        config(['translator.log_retention_days' => 0]);

        $this->artisan('translator:prune-logs')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);
    });

    // -------------------------------------------------------------------------
    // Actual deletion
    // -------------------------------------------------------------------------

    it('deletes import logs older than the retention window', function (): void {
        ImportLog::factory()->create(['created_at' => now()->subDays(100)]);
        ImportLog::factory()->create(['created_at' => now()->subDays(5)]);

        $this->artisan('translator:prune-logs --days=90')
            ->assertExitCode(0);

        expect(ImportLog::count())->toBe(1);
    });

    it('deletes export logs older than the retention window', function (): void {
        ExportLog::factory()->create(['created_at' => now()->subDays(100)]);
        ExportLog::factory()->create(['created_at' => now()->subDays(5)]);

        $this->artisan('translator:prune-logs --days=90')
            ->assertExitCode(0);

        expect(ExportLog::count())->toBe(1);
    });

    it('deletes AI translation logs older than the retention window', function (): void {
        AITranslationLog::factory()->create(['created_at' => now()->subDays(100)]);
        AITranslationLog::factory()->create(['created_at' => now()->subDays(5)]);

        $this->artisan('translator:prune-logs --days=90')
            ->assertExitCode(0);

        expect(AITranslationLog::count())->toBe(1);
    });

    it('deletes across all three log tables in one run', function (): void {
        ImportLog::factory()->create(['created_at' => now()->subDays(120)]);
        ExportLog::factory()->create(['created_at' => now()->subDays(120)]);
        AITranslationLog::factory()->create(['created_at' => now()->subDays(120)]);

        $this->artisan('translator:prune-logs --days=90')
            ->expectsOutputToContain('3')
            ->assertExitCode(0);

        expect(ImportLog::count())->toBe(0)
            ->and(ExportLog::count())->toBe(0)
            ->and(AITranslationLog::count())->toBe(0);
    });

    // -------------------------------------------------------------------------
    // --days override
    // -------------------------------------------------------------------------

    it('respects the --days option over the config value', function (): void {
        // Created 40 days ago — inside 90-day window, outside 30-day window.
        ImportLog::factory()->create(['created_at' => now()->subDays(40)]);

        $this->artisan('translator:prune-logs --days=30')
            ->assertExitCode(0);

        expect(ImportLog::count())->toBe(0);
    });

    it('does not delete recent records when --days is large', function (): void {
        ImportLog::factory()->create(['created_at' => now()->subDays(10)]);

        $this->artisan('translator:prune-logs --days=365')
            ->assertExitCode(0);

        expect(ImportLog::count())->toBe(1);
    });

    // -------------------------------------------------------------------------
    // --dry-run
    // -------------------------------------------------------------------------

    it('reports what would be deleted without deleting when --dry-run is used', function (): void {
        ImportLog::factory()->count(3)->create(['created_at' => now()->subDays(120)]);

        $this->artisan('translator:prune-logs --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        // Records must still exist.
        expect(ImportLog::count())->toBe(3);
    });

    it('shows the correct count in dry-run mode', function (): void {
        ImportLog::factory()->count(2)->create(['created_at' => now()->subDays(200)]);
        AITranslationLog::factory()->count(3)->create(['created_at' => now()->subDays(200)]);

        $this->artisan('translator:prune-logs --dry-run')
            ->expectsOutputToContain('5')
            ->assertExitCode(0);
    });
});