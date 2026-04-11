<?php

declare(strict_types=1);

use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

describe('TranslationPromptBuilder — translation memory', function (): void {

    beforeEach(function (): void {
        $this->builder = new TranslationPromptBuilder;

        $this->request = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            keys: ['auth.failed' => 'These credentials do not match our records.'],
            groupName: 'auth',
        );
    });

    // -------------------------------------------------------------------------
    // Memory enabled with reviewed data
    // -------------------------------------------------------------------------

    it('includes <translation_memory> block when memory is enabled and reviewed translations exist', function (): void {
        config(['translator.ai.translation_memory.enabled' => true]);

        $language = Language::factory()->create(['code' => 'fr', 'name' => 'French']);
        $group    = Group::factory()->auth()->create();
        $tkKey    = TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'failed']);

        Translation::factory()->reviewed('Identifiants incorrects.')->create([
            'translation_key_id' => $tkKey->id,
            'language_id'        => $language->id,
        ]);

        $prompt = $this->builder->buildSystemPrompt($this->request);

        expect($prompt)->toContain('<translation_memory>');
    });

    it('includes group-qualified key (e.g. auth.failed) in memory, not a bare key', function (): void {
        config(['translator.ai.translation_memory.enabled' => true]);

        $language = Language::factory()->create(['code' => 'fr', 'name' => 'French']);
        $group    = Group::factory()->auth()->create();
        $tkKey    = TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'failed']);

        Translation::factory()->reviewed('Identifiants incorrects.')->create([
            'translation_key_id' => $tkKey->id,
            'language_id'        => $language->id,
        ]);

        $prompt = $this->builder->buildSystemPrompt($this->request);

        // Must contain the qualified key, not the bare key.
        expect($prompt)
            ->toContain('auth.failed')
            ->not->toContain('"failed"');
    });

    it('includes the reviewed translation value in memory', function (): void {
        config(['translator.ai.translation_memory.enabled' => true]);

        $language = Language::factory()->create(['code' => 'fr', 'name' => 'French']);
        $group    = Group::factory()->auth()->create();
        $tkKey    = TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'failed']);

        Translation::factory()->reviewed('Identifiants incorrects.')->create([
            'translation_key_id' => $tkKey->id,
            'language_id'        => $language->id,
        ]);

        $prompt = $this->builder->buildSystemPrompt($this->request);

        expect($prompt)->toContain('Identifiants incorrects.');
    });

    // -------------------------------------------------------------------------
    // Memory disabled
    // -------------------------------------------------------------------------

    it('excludes <translation_memory> block when memory is disabled in config', function (): void {
        config(['translator.ai.translation_memory.enabled' => false]);

        // Seed reviewed data — should be ignored.
        $language = Language::factory()->create(['code' => 'fr', 'name' => 'French']);
        $group    = Group::factory()->auth()->create();
        $tkKey    = TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'failed']);

        Translation::factory()->reviewed('Identifiants incorrects.')->create([
            'translation_key_id' => $tkKey->id,
            'language_id'        => $language->id,
        ]);

        $prompt = $this->builder->buildSystemPrompt($this->request);

        expect($prompt)->not->toContain('<translation_memory>');
    });

    // -------------------------------------------------------------------------
    // No reviewed translations
    // -------------------------------------------------------------------------

    it('excludes <translation_memory> block when no reviewed translations exist for the target language', function (): void {
        config(['translator.ai.translation_memory.enabled' => true]);

        // Language exists but has only Translated (not Reviewed) entries.
        $language = Language::factory()->create(['code' => 'fr', 'name' => 'French']);
        $group    = Group::factory()->auth()->create();
        $tkKey    = TranslationKey::factory()->create(['group_id' => $group->id, 'key' => 'failed']);

        Translation::factory()->translated('Identifiants incorrects.')->create([
            'translation_key_id' => $tkKey->id,
            'language_id'        => $language->id,
        ]);

        $prompt = $this->builder->buildSystemPrompt($this->request);

        expect($prompt)->not->toContain('<translation_memory>');
    });

    it('excludes <translation_memory> block when no language record exists for the target locale', function (): void {
        config(['translator.ai.translation_memory.enabled' => true]);

        // No Language record for 'fr' in the database at all.
        $prompt = $this->builder->buildSystemPrompt($this->request);

        expect($prompt)->not->toContain('<translation_memory>');
    });

    // -------------------------------------------------------------------------
    // JSON group keys use bare form (no group prefix)
    // -------------------------------------------------------------------------

    it('uses bare key for JSON group entries in translation memory', function (): void {
        config(['translator.ai.translation_memory.enabled' => true]);

        $language  = Language::factory()->create(['code' => 'fr', 'name' => 'French']);
        $jsonGroup = Group::factory()->json()->create();
        $tkKey     = TranslationKey::factory()->create([
            'group_id' => $jsonGroup->id,
            'key'      => 'Welcome to our app',
        ]);

        Translation::factory()->reviewed('Bienvenue sur notre application.')->create([
            'translation_key_id' => $tkKey->id,
            'language_id'        => $language->id,
        ]);

        $jsonRequest = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            keys: ['Welcome to our app' => 'Welcome to our app'],
            groupName: '_json',
        );

        $prompt = $this->builder->buildSystemPrompt($jsonRequest);

        expect($prompt)
            ->toContain('<translation_memory>')
            ->toContain('Welcome to our app')
            ->not->toContain('_json.Welcome');
    });
});