<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\Translator\Events\ImportCompleted;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\TranslationKey;

describe('translator:import command', function (): void {

    beforeEach(function (): void {
        // Build an in-memory lang directory for each test.
        $this->langDir = sys_get_temp_dir().'/translator_cmd_import_'.uniqid();
        $enDir = $this->langDir.'/en';
        mkdir($enDir, 0755, true);

        // Create a simple auth.php group file.
        file_put_contents($enDir.'/auth.php', "<?php\nreturn [\n    'failed' => 'These credentials do not match.',\n    'throttle' => 'Too many login attempts. Please wait :seconds seconds.',\n];\n");

        config([
            'translator.lang_path' => $this->langDir,
            'translator.source_language' => 'en',
        ]);
    });

    afterEach(function (): void {
        @unlink($this->langDir.'/en/auth.php');
        @rmdir($this->langDir.'/en');
        @rmdir($this->langDir);
    });

    it('exits with SUCCESS and imports translation keys', function (): void {
        $this->artisan('translator:import')
            ->assertExitCode(0);

        expect(Language::where('code', 'en')->exists())->toBeTrue()
            ->and(Group::where('name', 'auth')->exists())->toBeTrue()
            ->and(TranslationKey::count())->toBeGreaterThan(0);
    });

    it('imports the correct key values from the PHP file', function (): void {
        $this->artisan('translator:import')->assertExitCode(0);

        expect(TranslationKey::where('key', 'failed')->exists())->toBeTrue()
            ->and(TranslationKey::where('key', 'throttle')->exists())->toBeTrue();
    });

    it('marks the imported language as active and source when configured', function (): void {
        $this->artisan('translator:import')->assertExitCode(0);

        $lang = Language::where('code', 'en')->first();
        expect($lang->active)->toBeTrue()
            ->and($lang->is_source)->toBeTrue();
    });

    it('dispatches ImportCompleted event on success', function (): void {
        Event::fake([ImportCompleted::class]);

        $this->artisan('translator:import')->assertExitCode(0);

        Event::assertDispatched(ImportCompleted::class);
    });

    it('purges all data when --fresh is passed with --no-interaction', function (): void {
        // First import to populate data.
        $this->artisan('translator:import')->assertExitCode(0);
        $firstCount = TranslationKey::count();
        expect($firstCount)->toBeGreaterThan(0);

        // Fresh import should wipe and re-import.
        $this->artisan('translator:import --fresh --no-interaction')->assertExitCode(0);

        // Key count should match the first import (same files).
        expect(TranslationKey::count())->toBe($firstCount);
    });

    it('does not overwrite existing values when --no-overwrite is used', function (): void {
        $this->artisan('translator:import')->assertExitCode(0);

        // Manually update a translation value.
        $key = TranslationKey::where('key', 'failed')->first();
        $translation = $key->translations()->first();
        $translation->update(['value' => 'CUSTOM VALUE']);

        // Re-import with no-overwrite.
        $this->artisan('translator:import --no-overwrite')->assertExitCode(0);

        expect($translation->fresh()->value)->toBe('CUSTOM VALUE');
    });
});
