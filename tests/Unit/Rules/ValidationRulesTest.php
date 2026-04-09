<?php

declare(strict_types=1);

use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;
use Syriable\Translator\Rules\TranslationParametersRule;
use Syriable\Translator\Rules\TranslationPluralRule;

describe('TranslationParametersRule', function (): void {

    beforeEach(function (): void {
        $this->group = Group::factory()->create([
            'name' => 'auth',
            'namespace' => null,
        ]);
    });

    it('passes when value is blank', function (): void {
        $key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'parameters' => [':name'],
        ]);
        $rule = new TranslationParametersRule($key);
        $fail = false;

        $rule->validate('value', '', static function () use (&$fail): void {
            $fail = true;
        });

        expect($fail)->toBeFalse();
    });

    it('passes when key has no parameters', function (): void {
        $key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'parameters' => null,
        ]);
        $rule = new TranslationParametersRule($key);
        $fail = false;

        $rule->validate('value', 'Some translated text.', static function () use (&$fail): void {
            $fail = true;
        });

        expect($fail)->toBeFalse();
    });

    it('passes when all parameters are present in the value', function (): void {
        $key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'parameters' => [':name', ':count'],
        ]);
        $rule = new TranslationParametersRule($key);
        $fail = false;

        $rule->validate('value', 'Hello :name, you have :count items.', static function () use (&$fail): void {
            $fail = true;
        });

        expect($fail)->toBeFalse();
    });

    it('fails when a required parameter is missing from the value', function (): void {
        $key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'parameters' => [':name', ':count'],
        ]);
        $rule = new TranslationParametersRule($key);
        $message = null;

        $rule->validate('value', 'Hello :name.', static function (string $msg) use (&$message): void {
            $message = $msg;
        });

        expect($message)->not->toBeNull();
    });

    it('missingParametersFor() returns only the absent tokens', function (): void {
        $key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'parameters' => [':name', ':count', ':date'],
        ]);

        $missing = TranslationParametersRule::missingParametersFor($key, 'Hello :name.');

        expect($missing)->toContain(':count')
            ->toContain(':date')
            ->not->toContain(':name');
    });
});

describe('TranslationPluralRule', function (): void {

    beforeEach(function (): void {
        $this->group = Group::factory()->create([
            'name' => 'messages',
        ]);

        // Set up a source language and translation for the plural source value.
        $this->sourceLanguage = Language::factory()->create([
            'code' => 'en',
            'is_source' => true,
            'active' => true,
        ]);
    });

    it('passes when key is not plural', function (): void {
        $key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'is_plural' => false,
        ]);
        $rule = new TranslationPluralRule($key);
        $fail = false;

        $rule->validate('value', 'one item', static function () use (&$fail): void {
            $fail = true;
        });

        expect($fail)->toBeFalse();
    });

    it('passes when value is blank', function (): void {
        $key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'is_plural' => true,
        ]);
        $rule = new TranslationPluralRule($key);
        $fail = false;

        $rule->validate('value', '', static function () use (&$fail): void {
            $fail = true;
        });

        expect($fail)->toBeFalse();
    });

    it('passes when the variant count matches the source', function (): void {
        $key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'is_plural' => true,
        ]);

        Translation::factory()->create([
            'translation_key_id' => $key->id,
            'language_id' => $this->sourceLanguage->id,
            'value' => 'one item|many items',
        ]);

        $rule = new TranslationPluralRule($key);
        $fail = false;

        $rule->validate('value', 'un élément|plusieurs éléments', static function () use (&$fail): void {
            $fail = true;
        });

        expect($fail)->toBeFalse();
    });

    it('fails when the variant count differs from the source', function (): void {
        $key = TranslationKey::factory()->create([
            'group_id' => $this->group->id,
            'is_plural' => true,
        ]);

        Translation::factory()->create([
            'translation_key_id' => $key->id,
            'language_id' => $this->sourceLanguage->id,
            'value' => 'one item|few items|many items',
        ]);

        $rule = new TranslationPluralRule($key);
        $message = null;

        $rule->validate('value', 'just one form', static function (string $msg) use (&$message): void {
            $message = $msg;
        });

        expect($message)->not->toBeNull();
    });
});
