<?php

declare(strict_types=1);

use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;
use Syriable\Translator\Enums\TranslationStatus;
use Syriable\Translator\Models\Group;
use Syriable\Translator\Models\Language;
use Syriable\Translator\Models\Translation;
use Syriable\Translator\Models\TranslationKey;
use Syriable\Translator\Services\AI\AITranslationService;

/**
 * Regression tests for the group-scoping fix in AITranslationService::persistTranslations().
 *
 * Before the fix, keys were looked up by name alone:
 *   TranslationKey::query()->whereIn('key', $keys)
 * This caused silent cross-group collisions when two groups contained a key
 * with the same name (e.g. 'failed' in 'auth' and 'failed' in 'passwords').
 *
 * After the fix, the lookup is scoped by group_id, so each request only touches
 * the TranslationKey records that belong to its own group.
 */
describe('AITranslationService — cross-group key collision regression', function (): void {

    beforeEach(function (): void {
        // Build two groups that both contain a 'failed' key.
        $this->language = Language::factory()->create(['code' => 'fr', 'name' => 'French', 'active' => true]);

        $this->authGroup = Group::factory()->create(['name' => 'auth',      'namespace' => null]);
        $this->passwordsGroup = Group::factory()->create(['name' => 'passwords', 'namespace' => null]);

        $this->authKey = TranslationKey::factory()->create([
            'group_id' => $this->authGroup->id,
            'key' => 'failed',
        ]);

        $this->passwordsKey = TranslationKey::factory()->create([
            'group_id' => $this->passwordsGroup->id,
            'key' => 'failed',
        ]);
    });

    it('writes translations only to the key belonging to the requested group', function (): void {
        // Build a minimal response that translates the 'failed' key for the 'auth' group.
        $request = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            keys: ['failed' => 'These credentials do not match our records.'],
            groupName: 'auth',
            namespace: null,
        );

        $response = new TranslationResponse(
            provider: 'claude',
            model: 'claude-haiku-4-5-20251001',
            translations: ['failed' => 'Identifiants incorrects.'],
            failedKeys: [],
            inputTokensUsed: 100,
            outputTokensUsed: 50,
            actualCostUsd: 0.0,
            durationMs: 0,
        );

        // Invoke persistTranslations() via the service using reflection so we can
        // test the private method directly without mocking the full provider stack.
        $service = app(AITranslationService::class);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('persistTranslations');
        $method->setAccessible(true);
        $method->invoke($service, $response, $this->language, $request);

        // Only the 'auth' key should have a translation row.
        expect(Translation::where('translation_key_id', $this->authKey->id)
            ->where('language_id', $this->language->id)
            ->exists()
        )->toBeTrue();

        // The 'passwords' key must NOT have been written.
        expect(Translation::where('translation_key_id', $this->passwordsKey->id)
            ->where('language_id', $this->language->id)
            ->exists()
        )->toBeFalse();
    });

    it('writes the correct translated value to the scoped key', function (): void {
        $request = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            keys: ['failed' => 'These credentials do not match our records.'],
            groupName: 'auth',
            namespace: null,
        );

        $response = new TranslationResponse(
            provider: 'claude',
            model: 'claude-haiku-4-5-20251001',
            translations: ['failed' => 'Identifiants incorrects.'],
            failedKeys: [],
            inputTokensUsed: 100,
            outputTokensUsed: 50,
            actualCostUsd: 0.0,
            durationMs: 0,
        );

        $service = app(AITranslationService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('persistTranslations');
        $method->setAccessible(true);
        $method->invoke($service, $response, $this->language, $request);

        $translation = Translation::where('translation_key_id', $this->authKey->id)
            ->where('language_id', $this->language->id)
            ->first();

        expect($translation)->not->toBeNull()
            ->and($translation->value)->toBe('Identifiants incorrects.')
            ->and($translation->status)->toBe(TranslationStatus::Translated);
    });

    it('persists both groups independently without cross-contamination', function (): void {
        $authRequest = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            keys: ['failed' => 'These credentials do not match our records.'],
            groupName: 'auth',
            namespace: null,
        );

        $passwordsRequest = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            keys: ['failed' => 'This password reset token is invalid.'],
            groupName: 'passwords',
            namespace: null,
        );

        $authResponse = new TranslationResponse(
            provider: 'claude',
            model: 'claude-haiku-4-5-20251001',
            translations: ['failed' => 'Identifiants incorrects.'],
            failedKeys: [],
            inputTokensUsed: 100,
            outputTokensUsed: 50,
            actualCostUsd: 0.0,
            durationMs: 0,
        );

        $passwordsResponse = new TranslationResponse(
            provider: 'claude',
            model: 'claude-haiku-4-5-20251001',
            translations: ['failed' => 'Ce jeton de réinitialisation est invalide.'],
            failedKeys: [],
            inputTokensUsed: 100,
            outputTokensUsed: 50,
            actualCostUsd: 0.0,
            durationMs: 0,
        );

        $service = app(AITranslationService::class);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('persistTranslations');
        $method->setAccessible(true);

        $method->invoke($service, $authResponse, $this->language, $authRequest);
        $method->invoke($service, $passwordsResponse, $this->language, $passwordsRequest);

        $authTranslation = Translation::where('translation_key_id', $this->authKey->id)
            ->where('language_id', $this->language->id)
            ->first();

        $passwordsTranslation = Translation::where('translation_key_id', $this->passwordsKey->id)
            ->where('language_id', $this->language->id)
            ->first();

        expect($authTranslation)->not->toBeNull()
            ->and($authTranslation->value)->toBe('Identifiants incorrects.');

        expect($passwordsTranslation)->not->toBeNull()
            ->and($passwordsTranslation->value)->toBe('Ce jeton de réinitialisation est invalide.');
    });
});
