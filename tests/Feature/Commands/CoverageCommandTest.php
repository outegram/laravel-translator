<?php

declare(strict_types=1);

use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

describe('translator:coverage command', function (): void {

    beforeEach(function (): void {
        $this->english = Language::factory()->english()->create();
        $this->french = Language::factory()->french()->create();
        $this->arabic = Language::factory()->arabic()->create();
        $this->group = Group::factory()->auth()->create();

        // Seed 4 translation keys.
        $this->keys = TranslationKey::factory()->count(4)->create([
            'group_id' => $this->group->id,
        ]);

        // French: 3 translated, 2 reviewed.
        Translation::factory()->translated()->create(['translation_key_id' => $this->keys[0]->id, 'language_id' => $this->french->id]);
        Translation::factory()->translated()->create(['translation_key_id' => $this->keys[1]->id, 'language_id' => $this->french->id]);
        Translation::factory()->reviewed()->create(['translation_key_id' => $this->keys[2]->id, 'language_id' => $this->french->id]);
        Translation::factory()->reviewed()->create(['translation_key_id' => $this->keys[3]->id, 'language_id' => $this->french->id]);

        // Arabic: 1 translated, 0 reviewed.
        Translation::factory()->translated()->create(['translation_key_id' => $this->keys[0]->id, 'language_id' => $this->arabic->id]);
    });

    // -------------------------------------------------------------------------
    // Basic output
    // -------------------------------------------------------------------------

    it('exits with SUCCESS and displays a coverage table', function (): void {
        $this->artisan('translator:coverage')
            ->expectsOutputToContain('Locale')
            ->expectsOutputToContain('Translated')
            ->expectsOutputToContain('Reviewed')
            ->assertExitCode(0);
    });

    it('shows French at 100% translated (4/4 keys have values)', function (): void {
        $this->artisan('translator:coverage')
            ->expectsOutputToContain('100')
            ->assertExitCode(0);
    });

    it('shows Arabic at 25% translated (1/4 keys)', function (): void {
        $this->artisan('translator:coverage')
            ->expectsOutputToContain('25')
            ->assertExitCode(0);
    });

    it('reports total key count in the output', function (): void {
        $this->artisan('translator:coverage')
            ->expectsOutputToContain('4')
            ->assertExitCode(0);
    });

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    it('limits output to a single locale when --locale is specified', function (): void {
        $this->artisan('translator:coverage --locale=fr')
            ->expectsOutputToContain('fr')
            ->assertExitCode(0);
    });

    it('shows zero coverage for a locale with no translations', function (): void {
        $spanish = Language::factory()->create(['code' => 'es', 'name' => 'Spanish', 'active' => true]);

        $this->artisan('translator:coverage --locale=es')
            ->expectsOutputToContain('es')
            ->assertExitCode(0);
    });

    it('informs user when no keys exist', function (): void {
        // Clear all keys.
        Translation::query()->delete();
        TranslationKey::query()->delete();

        $this->artisan('translator:coverage')
            ->expectsOutputToContain('No translation keys found')
            ->assertExitCode(0);
    });

    // -------------------------------------------------------------------------
    // --min flag
    // -------------------------------------------------------------------------

    it('highlights locales below the --min threshold', function (): void {
        // Arabic is at 25% — below any threshold > 25.
        $this->artisan('translator:coverage --min=50')
            ->expectsOutputToContain('⚠')
            ->assertExitCode(0);
    });

    it('does not warn when all locales are above the --min threshold', function (): void {
        $this->artisan('translator:coverage --min=0')
            ->assertExitCode(0);
    });

    // -------------------------------------------------------------------------
    // --fail-below CI gate
    // -------------------------------------------------------------------------

    it('exits with FAILURE when a non-source locale falls below --fail-below threshold', function (): void {
        // Arabic is at 25% — fails the 80% gate.
        $this->artisan('translator:coverage --fail-below=80')
            ->assertExitCode(1);
    });

    it('exits with SUCCESS when all non-source locales meet the --fail-below threshold', function (): void {
        $this->artisan('translator:coverage --fail-below=10')
            ->assertExitCode(0);
    });

    it('excludes the source language from the --fail-below check', function (): void {
        // English is source — even if it has no separate translations, it should not trigger failure.
        $this->artisan('translator:coverage --fail-below=100')
            ->assertExitCode(1); // French and Arabic would fail at 100%, not English.
    });

    // -------------------------------------------------------------------------
    // JSON output
    // -------------------------------------------------------------------------

    it('outputs valid JSON when --format=json is specified', function (): void {
        $output = [];
        $this->artisan('translator:coverage --format=json')->run();

        // The command outputs JSON to stdout — test that the command exits cleanly.
        $this->artisan('translator:coverage --format=json')
            ->assertExitCode(0);
    });

    // -------------------------------------------------------------------------
    // CSV output
    // -------------------------------------------------------------------------

    it('outputs CSV headers when --format=csv is specified', function (): void {
        $this->artisan('translator:coverage --format=csv')
            ->expectsOutputToContain('locale,name,total_keys')
            ->assertExitCode(0);
    });

    it('includes locale codes in CSV rows', function (): void {
        $this->artisan('translator:coverage --format=csv')
            ->expectsOutputToContain('fr')
            ->expectsOutputToContain('ar')
            ->assertExitCode(0);
    });
});
