<?php

declare(strict_types=1);

use Syriable\Translator\AI\Contracts\TranslationProviderInterface;
use Syriable\Translator\AI\Drivers\ClaudeDriver;
use Syriable\Translator\AI\Estimators\TokenEstimator;
use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;
use Syriable\Translator\AI\TranslationProviderManager;
use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;

describe('TranslationProviderManager', function (): void {

    beforeEach(function (): void {
        $this->manager = new TranslationProviderManager(
            container: app(),
            estimator: new TokenEstimator,
            promptBuilder: new TranslationPromptBuilder,
        );
    });

    it('resolves the claude driver by name', function (): void {
        config(['translator.ai.providers.claude.api_key' => 'sk-ant-test']);

        $driver = $this->manager->driver('claude');

        expect($driver)->toBeInstanceOf(ClaudeDriver::class);
    });

    it('returns the same instance on repeated calls (singleton per name)', function (): void {
        config(['translator.ai.providers.claude.api_key' => 'sk-ant-test']);

        $first = $this->manager->driver('claude');
        $second = $this->manager->driver('claude');

        expect($first)->toBe($second);
    });

    it('uses the configured default provider when no name is given', function (): void {
        config([
            'translator.ai.default_provider' => 'claude',
            'translator.ai.providers.claude.api_key' => 'sk-ant-test',
        ]);

        $driver = $this->manager->driver();

        expect($driver)->toBeInstanceOf(ClaudeDriver::class);
    });

    it('throws InvalidArgumentException for unknown providers', function (): void {
        expect(fn () => $this->manager->driver('unknown_provider'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('allows registering custom drivers via extend()', function (): void {
        $stub = new class implements TranslationProviderInterface
        {
            public function estimate(TranslationRequest $r): TranslationEstimate
            {
                return new TranslationEstimate('stub', 'stub-1', 0, 0, 0.0, 0, 0);
            }

            public function translate(TranslationRequest $r): TranslationResponse
            {
                return new TranslationResponse('stub', 'stub-1', [], [], 0, 0, 0.0, 0);
            }

            public function providerName(): string
            {
                return 'stub';
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };

        $this->manager->extend('stub', static fn () => $stub);

        expect($this->manager->driver('stub'))->toBe($stub);
    });

    it('custom driver takes precedence over a built-in driver of the same name', function (): void {
        $custom = new class implements TranslationProviderInterface
        {
            public function estimate(TranslationRequest $r): TranslationEstimate
            {
                return new TranslationEstimate('claude-custom', 'custom', 0, 0, 0.0, 0, 0);
            }

            public function translate(TranslationRequest $r): TranslationResponse
            {
                return new TranslationResponse('claude-custom', 'custom', [], [], 0, 0, 0.0, 0);
            }

            public function providerName(): string
            {
                return 'claude';
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };

        $this->manager->extend('claude', static fn () => $custom);

        expect($this->manager->driver('claude'))->toBe($custom);
    });

    it('lists all available provider names including custom ones', function (): void {
        $this->manager->extend('gemini', static fn () => new class implements TranslationProviderInterface
        {
            public function estimate(TranslationRequest $r): TranslationEstimate
            {
                return new TranslationEstimate('gemini', 'g-1', 0, 0, 0.0, 0, 0);
            }

            public function translate(TranslationRequest $r): TranslationResponse
            {
                return new TranslationResponse('gemini', 'g-1', [], [], 0, 0, 0.0, 0);
            }

            public function providerName(): string
            {
                return 'gemini';
            }

            public function isAvailable(): bool
            {
                return true;
            }
        });

        expect($this->manager->availableProviders())
            ->toContain('claude')
            ->toContain('chatgpt')
            ->toContain('gemini');
    });
});
