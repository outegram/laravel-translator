<?php

declare(strict_types=1);

use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;
use Syriable\Translator\DTOs\AI\TranslationRequest;

describe('TranslationPromptBuilder', function (): void {

    beforeEach(function (): void {
        $this->builder = new TranslationPromptBuilder;

        $this->request = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'ar',
            keys: [
                'auth.failed' => 'These credentials do not match our records.',
                'auth.throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
            ],
            groupName: 'auth',
        );
    });

    describe('buildSystemPrompt()', function (): void {

        it('includes the source and target language codes', function (): void {
            $prompt = $this->builder->buildSystemPrompt($this->request);

            expect($prompt)->toContain('en')
                ->toContain('ar');
        });

        it('includes placeholder preservation rules', function (): void {
            $prompt = $this->builder->buildSystemPrompt($this->request);

            expect($prompt)->toContain(':name')
                ->toContain('{count}');
        });

        it('mentions plural handling', function (): void {
            $prompt = $this->builder->buildSystemPrompt($this->request);

            expect($prompt)->toContain('|');
        });

        it('instructs the model to return only JSON', function (): void {
            $prompt = $this->builder->buildSystemPrompt($this->request);

            expect($prompt)->toContain('JSON');
        });
    });

    describe('buildUserMessage()', function (): void {

        it('includes the source and target language codes', function (): void {
            $message = $this->builder->buildUserMessage($this->request);

            expect($message)->toContain('en')
                ->toContain('ar');
        });

        it('includes all translation key names in the message', function (): void {
            $message = $this->builder->buildUserMessage($this->request);

            expect($message)->toContain('auth.failed')
                ->toContain('auth.throttle');
        });

        it('includes source string values', function (): void {
            $message = $this->builder->buildUserMessage($this->request);

            expect($message)->toContain('These credentials do not match our records.');
        });

        it('includes the group name', function (): void {
            $message = $this->builder->buildUserMessage($this->request);

            expect($message)->toContain('auth');
        });

        it('includes vendor namespace in qualified group name when set', function (): void {
            $vendorRequest = new TranslationRequest(
                sourceLanguage: 'en',
                targetLanguage: 'fr',
                keys: ['permission.created' => 'Permission created.'],
                groupName: 'permissions',
                namespace: 'spatie',
            );

            $message = $this->builder->buildUserMessage($vendorRequest);

            expect($message)->toContain('spatie::permissions');
        });

        it('includes optional context when provided', function (): void {
            $contextRequest = new TranslationRequest(
                sourceLanguage: 'en',
                targetLanguage: 'de',
                keys: ['key' => 'value'],
                groupName: 'general',
                context: 'E-commerce platform for selling handmade goods.',
            );

            $message = $this->builder->buildUserMessage($contextRequest);

            expect($message)->toContain('E-commerce platform for selling handmade goods.');
        });
    });

    describe('measurePromptLength()', function (): void {

        it('returns a positive integer for non-empty requests', function (): void {
            expect($this->builder->measurePromptLength($this->request))->toBeGreaterThan(0);
        });

        it('returns a greater length for more keys', function (): void {
            $smallRequest = new TranslationRequest('en', 'fr', ['k' => 'v'], 'group');
            $largeRequest = new TranslationRequest('en', 'fr', array_fill(0, 50, 'A longer value here.'), 'group');

            expect($this->builder->measurePromptLength($largeRequest))
                ->toBeGreaterThan($this->builder->measurePromptLength($smallRequest));
        });
    });
});
