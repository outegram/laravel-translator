<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Events\ExportCompleted;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

describe('translator:export command', function (): void {

    beforeEach(function (): void {
        $this->outputDir = sys_get_temp_dir().'/translator_cmd_export_'.uniqid();
        mkdir($this->outputDir, 0755, true);

        config([
            'translator.lang_path' => $this->outputDir,
            'translator.source_language' => 'en',
        ]);

        // Seed the database with languages, a group, and translated keys.
        $this->english = Language::factory()->english()->create();
        $this->french = Language::factory()->french()->create();
        $this->group = Group::factory()->auth()->create();

        $this->key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'key' => 'failed',
        ]);

        // Source (English) translation.
        Translation::factory()->translated('These credentials do not match.')->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->english->id,
        ]);

        // Target (French) translation.
        Translation::factory()->translated('Ces identifiants ne correspondent pas.')->create([
            'translation_key_id' => $this->key->id,
            'language_id' => $this->french->id,
        ]);
    });

    afterEach(function (): void {
        // Clean up output files.
        foreach (glob($this->outputDir.'/**/*.php') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->outputDir.'/*.json') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->outputDir.'/*', GLOB_ONLYDIR) as $d) {
            @rmdir($d);
        }
        @rmdir($this->outputDir);
    });

    it('exits with SUCCESS and writes files to disk', function (): void {
        $this->artisan('translator:export')->assertExitCode(0);

        expect(file_exists($this->outputDir.'/en/auth.php'))->toBeTrue()
            ->and(file_exists($this->outputDir.'/fr/auth.php'))->toBeTrue();
    });

    it('writes correct translation values to the PHP file', function (): void {
        $this->artisan('translator:export')->assertExitCode(0);

        $frContent = require $this->outputDir.'/fr/auth.php';

        expect($frContent['failed'])->toBe('Ces identifiants ne correspondent pas.');
    });

    it('exports only the specified locale when --locale is given', function (): void {
        $this->artisan('translator:export --locale=fr')->assertExitCode(0);

        expect(file_exists($this->outputDir.'/fr/auth.php'))->toBeTrue()
            ->and(file_exists($this->outputDir.'/en/auth.php'))->toBeFalse();
    });

    it('exports only the specified group when --group is given', function (): void {
        // Create a second group with a translation.
        $validationGroup = Group::factory()->validation()->create();
        $valKey = TranslationKey::factory()->create(['group_id' => $validationGroup->id, 'key' => 'required']);
        Translation::factory()->translated('This field is required.')->create([
            'translation_key_id' => $valKey->id,
            'language_id' => $this->english->id,
        ]);

        $this->artisan('translator:export --group=auth')->assertExitCode(0);

        expect(file_exists($this->outputDir.'/en/auth.php'))->toBeTrue()
            ->and(file_exists($this->outputDir.'/en/validation.php'))->toBeFalse();
    });

    it('dispatches ExportCompleted event on success', function (): void {
        Event::fake([ExportCompleted::class]);

        $this->artisan('translator:export')->assertExitCode(0);

        Event::assertDispatched(ExportCompleted::class);
    });

    it('skips untranslated keys and does not write empty files', function (): void {
        // Create a group with only an untranslated key.
        $emptyGroup = Group::factory()->create(['name' => 'empty_group']);
        $emptyKey = TranslationKey::factory()->create(['group_id' => $emptyGroup->id, 'key' => 'some.key']);
        Translation::factory()->create([
            'translation_key_id' => $emptyKey->id,
            'language_id' => $this->french->id,
            'value' => null,
            'status' => TranslationStatus::Untranslated,
        ]);

        $this->artisan('translator:export')->assertExitCode(0);

        expect(file_exists($this->outputDir.'/fr/empty_group.php'))->toBeFalse();
    });
});
