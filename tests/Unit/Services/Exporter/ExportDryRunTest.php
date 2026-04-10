<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\Translator\Events\ExportCompleted;
use Syriable\Translator\Models\ExportLog;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

describe('translator:export --dry-run', function (): void {

    beforeEach(function (): void {
        $this->outputDir = sys_get_temp_dir().'/translator_dryrun_'.uniqid();
        mkdir($this->outputDir, 0755, true);

        config([
            'translator.lang_path' => $this->outputDir,
            'translator.source_language' => 'en',
        ]);

        $this->english = Language::factory()->english()->create();
        $this->french = Language::factory()->french()->create();
        $this->group = Group::factory()->auth()->create();
        $this->key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'key' => 'failed',
        ]);

        Translation::factory()->translated('These credentials do not match.')->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->english->id,
        ]);

        Translation::factory()->translated('Ces identifiants ne correspondent pas.')->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->french->id,
        ]);
    });

    afterEach(function (): void {
        array_map('unlink', glob($this->outputDir.'/**/*.php') ?: []);
        array_map('unlink', glob($this->outputDir.'/*.json') ?: []);
        foreach (glob($this->outputDir.'/*', GLOB_ONLYDIR) as $d) {
            @rmdir($d);
        }
        @rmdir($this->outputDir);
    });

    it('does not write any files to disk in dry-run mode', function (): void {
        $this->artisan('translator:export --dry-run')
            ->assertExitCode(0);

        expect(file_exists($this->outputDir.'/en/auth.php'))->toBeFalse()
            ->and(file_exists($this->outputDir.'/fr/auth.php'))->toBeFalse();
    });

    it('outputs a dry-run notice and the list of paths that would be written', function (): void {
        $this->artisan('translator:export --dry-run')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('auth.php')
            ->assertExitCode(0);
    });

    it('does not create an ExportLog record in dry-run mode', function (): void {
        $this->artisan('translator:export --dry-run')->assertExitCode(0);

        expect(ExportLog::count())->toBe(0);
    });

    it('does not dispatch ExportCompleted in dry-run mode', function (): void {
        Event::fake([ExportCompleted::class]);

        $this->artisan('translator:export --dry-run')->assertExitCode(0);

        Event::assertNotDispatched(ExportCompleted::class);
    });

    it('creates an ExportLog and dispatches ExportCompleted on a real (non-dry) export', function (): void {
        Event::fake([ExportCompleted::class]);

        $this->artisan('translator:export')->assertExitCode(0);

        expect(ExportLog::count())->toBe(1);
        Event::assertDispatched(ExportCompleted::class);
    });

    it('still reports correct counts in dry-run mode', function (): void {
        $this->artisan('translator:export --dry-run')
            ->expectsOutputToContain('2') // 2 locales
            ->assertExitCode(0);
    });

    it('honours --locale scope in dry-run mode', function (): void {
        $this->artisan('translator:export --locale=fr --dry-run')
            ->expectsOutputToContain('fr')
            ->assertExitCode(0);

        // English file must not exist (scope applied).
        expect(file_exists($this->outputDir.'/en/auth.php'))->toBeFalse();
    });
});
