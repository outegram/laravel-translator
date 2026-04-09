<?php

declare(strict_types=1);

use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;

describe('TranslationKey model', function (): void {

    beforeEach(function (): void {
        $this->group = Group::factory()->create();
    });

    // -------------------------------------------------------------------------
    // hasParameters / parameterNames
    // -------------------------------------------------------------------------

    it('reports hasParameters() as false when parameters is null', function (): void {
        $key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'parameters' => null,
        ]);

        expect($key->hasParameters())->toBeFalse();
    });

    it('reports hasParameters() as true when parameters are present', function (): void {
        $key = TranslationKey::factory()->withParameters([':name', ':count'])->create([
            'group_id' => $this->group->id,
        ]);

        expect($key->hasParameters())->toBeTrue();
    });

    it('returns the parameter names list via parameterNames()', function (): void {
        $key = TranslationKey::factory()->withParameters([':name', '{count}'])->create([
            'group_id' => $this->group->id,
        ]);

        expect($key->parameterNames())->toBe([':name', '{count}']);
    });

    it('returns an empty array from parameterNames() when parameters is null', function (): void {
        $key = TranslationKey::factory()->create(['group_id' => $this->group->id, 'parameters' => null]);

        expect($key->parameterNames())->toBe([]);
    });

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    it('withParameters scope returns only keys that have parameters', function (): void {
        TranslationKey::factory()->withParameters([':name'])->create(['group_id' => $this->group->id]);
        TranslationKey::factory()->create(['group_id' => $this->group->id, 'parameters' => null]);

        $results = TranslationKey::query()->withParameters()->get();

        expect($results)->toHaveCount(1);
    });

    it('plural scope returns only plural keys', function (): void {
        TranslationKey::factory()->plural()->create(['group_id' => $this->group->id]);
        TranslationKey::factory()->create(['group_id' => $this->group->id]);

        expect(TranslationKey::query()->plural()->count())->toBe(1);
    });

    it('html scope returns only HTML-flagged keys', function (): void {
        TranslationKey::factory()->html()->create(['group_id' => $this->group->id]);
        TranslationKey::factory()->create(['group_id' => $this->group->id]);

        expect(TranslationKey::query()->html()->count())->toBe(1);
    });

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    it('belongs to a Group', function (): void {
        $key = TranslationKey::factory()->create(['group_id' => $this->group->id]);

        expect($key->group->id)->toBe($this->group->id);
    });

    it('has many Translations', function (): void {
        $key = TranslationKey::factory()->create(['group_id' => $this->group->id]);
        $langs = Language::factory()->count(2)->create();

        foreach ($langs as $lang) {
            Translation::factory()->create([
                'translation_key_id' => $key->id,
                'language_id' => $lang->id,
            ]);
        }

        expect($key->translations)->toHaveCount(2);
    });
});
