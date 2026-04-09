<?php

declare(strict_types=1);

use Syriable\Translator\DTOs\AI\TranslationRequest;

describe('TranslationRequest', function (): void {

    it('counts keys correctly', function (): void {
        $request = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            keys: ['a' => 'A value', 'b' => 'B value', 'c' => 'C value'],
            groupName: 'test',
        );

        expect($request->keyCount())->toBe(3);
    });

    it('sums source character counts correctly', function (): void {
        $request = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            keys: ['k1' => 'hello', 'k2' => 'world'],  // 5 + 5 = 10 chars
            groupName: 'test',
        );

        expect($request->totalSourceCharacters())->toBe(10);
    });

    it('returns a qualified group name for application groups', function (): void {
        $request = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'ar',
            keys: [],
            groupName: 'auth',
        );

        expect($request->qualifiedGroupName())->toBe('auth');
    });

    it('returns a namespaced qualified group name for vendor groups', function (): void {
        $request = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'ar',
            keys: [],
            groupName: 'permissions',
            namespace: 'spatie',
        );

        expect($request->qualifiedGroupName())->toBe('spatie::permissions');
    });

    it('defaults preservePlurals to true', function (): void {
        $request = new TranslationRequest('en', 'fr', [], 'group');

        expect($request->preservePlurals)->toBeTrue();
    });

    it('stores context when provided', function (): void {
        $request = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'de',
            keys: [],
            groupName: 'group',
            context: 'E-commerce platform.',
        );

        expect($request->context)->toBe('E-commerce platform.');
    });
});
