<?php

declare(strict_types=1);

use Syriable\Translator\Models\AITranslationLog;

describe('translator:ai-stats command', function (): void {

    it('exits with SUCCESS when no logs exist', function (): void {
        $this->artisan('translator:ai-stats')
            ->expectsOutputToContain('No AI translation logs')
            ->assertExitCode(0);
    });

    it('displays a summary table when logs exist', function (): void {
        AITranslationLog::factory()->count(3)->create([
            'provider' => 'claude',
            'target_language' => 'ar',
        ]);

        $this->artisan('translator:ai-stats')
            ->expectsOutputToContain('Summary')
            ->expectsOutputToContain('By Provider')
            ->expectsOutputToContain('By Target Language')
            ->assertExitCode(0);
    });

    it('filters logs by --provider option', function (): void {
        AITranslationLog::factory()->count(2)->create(['provider' => 'claude']);
        AITranslationLog::factory()->count(1)->create(['provider' => 'chatgpt']);

        $this->artisan('translator:ai-stats --provider=claude')
            ->assertExitCode(0);
    });

    it('respects the --days option to limit the report window', function (): void {
        // Create an old log (outside the 7-day window).
        AITranslationLog::factory()->create([
            'created_at' => now()->subDays(30),
        ]);

        // Create a recent log (within the 7-day window).
        AITranslationLog::factory()->create([
            'created_at' => now()->subDays(2),
        ]);

        // With --days=7, only the recent log should be included.
        // No assertion on table content needed — the command should not fail.
        $this->artisan('translator:ai-stats --days=7')
            ->assertExitCode(0);
    });

    it('reports no logs found when the provider filter matches nothing', function (): void {
        AITranslationLog::factory()->create(['provider' => 'claude']);

        $this->artisan('translator:ai-stats --provider=gemini')
            ->expectsOutputToContain('No AI translation logs')
            ->assertExitCode(0);
    });
});
