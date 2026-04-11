<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Syriable\Translator\AI\Drivers\ClaudeDriver;
use Syriable\Translator\AI\Estimators\TokenEstimator;
use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\Exceptions\AI\ProviderAuthenticationException;
use Syriable\Translator\Exceptions\AI\ProviderRateLimitException;
use Syriable\Translator\Exceptions\AI\TranslationProviderException;
use Syriable\Translator\Models\Language;

/**
 * Comprehensive test suite for ClaudeDriver using Http::fake().
 *
 * All HTTP calls are intercepted — no real API requests are made.
 * Tests cover the happy path, error handling, response parsing edge cases,
 * cost calculation, and the estimate-only (no-HTTP) path.
 */
describe('ClaudeDriver', function (): void {

    beforeEach(function (): void {
        config([
            'translator.ai.providers.claude.api_key' => 'sk-ant-api03-test',
            'translator.ai.providers.claude.model' => 'claude-haiku-4-5-20251001',
            'translator.ai.providers.claude.max_tokens' => 4096,
            'translator.ai.providers.claude.timeout_seconds' => 30,
            'translator.ai.providers.claude.max_retries' => 1,
            'translator.ai.providers.claude.input_cost_per_1k_tokens' => 0.003,
            'translator.ai.providers.claude.output_cost_per_1k_tokens' => 0.015,
            'translator.ai.translation_memory.enabled' => false,
        ]);

        Language::factory()->english()->create();
        Language::factory()->french()->create();

        $this->driver = new ClaudeDriver(
            estimator: new TokenEstimator,
            promptBuilder: new TranslationPromptBuilder,
        );

        $this->request = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            keys: ['auth.failed' => 'These credentials do not match our records.'],
            groupName: 'auth',
        );

        $this->multiRequest = new TranslationRequest(
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            keys: [
                'auth.failed' => 'These credentials do not match.',
                'auth.throttle' => 'Too many attempts. Try in :seconds seconds.',
            ],
            groupName: 'auth',
        );
    });

    // -------------------------------------------------------------------------
    // Successful translations
    // -------------------------------------------------------------------------

    it('translates a single key successfully via the Anthropic Messages API', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(claudeDriverSuccessResponse(
                '{"auth.failed": "Identifiants incorrects."}',
            ), 200),
        ]);

        $response = $this->driver->translate($this->request);

        expect($response->translations)->toHaveKey('auth.failed', 'Identifiants incorrects.')
            ->and($response->failedKeys)->toBeEmpty()
            ->and($response->provider)->toBe('claude')
            ->and($response->inputTokensUsed)->toBe(150)
            ->and($response->outputTokensUsed)->toBe(48);
    });

    it('translates multiple keys in a single request', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(claudeDriverSuccessResponse(
                '{"auth.failed": "Identifiants incorrects.", "auth.throttle": "Trop de tentatives. Réessayez dans :seconds secondes."}',
                inputTokens: 300,
                outputTokens: 90,
            ), 200),
        ]);

        $response = $this->driver->translate($this->multiRequest);

        expect($response->translations)
            ->toHaveKey('auth.failed', 'Identifiants incorrects.')
            ->toHaveKey('auth.throttle', 'Trop de tentatives. Réessayez dans :seconds secondes.')
            ->and($response->failedKeys)->toBeEmpty();
    });

    it('records keys missing from the API response as failed keys', function (): void {
        // API returns only auth.failed — auth.throttle is missing.
        Http::fake([
            'api.anthropic.com/*' => Http::response(claudeDriverSuccessResponse(
                '{"auth.failed": "Identifiants incorrects."}',
            ), 200),
        ]);

        $response = $this->driver->translate($this->multiRequest);

        expect($response->translations)->toHaveKey('auth.failed')
            ->and($response->failedKeys)->toContain('auth.throttle')
            ->and($response->failedKeys)->toHaveCount(1);
    });

    it('computes actual cost from reported token usage', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(claudeDriverSuccessResponse(
                '{"auth.failed": "Identifiants incorrects."}',
                inputTokens: 1000,
                outputTokens: 500,
            ), 200),
        ]);

        $response = $this->driver->translate($this->request);

        // 1000 * 0.003/1000 + 500 * 0.015/1000 = 0.003 + 0.0075 = 0.0105
        expect($response->actualCostUsd)->toBe(0.0105);
    });

    it('uses the model name returned by the API in the response', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(array_merge(
                claudeDriverSuccessResponse('{"auth.failed": "Test."}'),
                ['model' => 'claude-opus-4-6'],
            ), 200),
        ]);

        $response = $this->driver->translate($this->request);

        expect($response->model)->toBe('claude-opus-4-6');
    });

    // -------------------------------------------------------------------------
    // Response parsing edge cases
    // -------------------------------------------------------------------------

    it('strips markdown JSON code fences from the response', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(claudeDriverSuccessResponse(
                "```json\n{\"auth.failed\": \"Identifiants incorrects.\"}\n```",
            ), 200),
        ]);

        $response = $this->driver->translate($this->request);

        expect($response->translations)->toHaveKey('auth.failed', 'Identifiants incorrects.');
    });

    it('strips plain code fences without a language tag', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(claudeDriverSuccessResponse(
                "```\n{\"auth.failed\": \"Identifiants incorrects.\"}\n```",
            ), 200),
        ]);

        $response = $this->driver->translate($this->request);

        expect($response->translations)->toHaveKey('auth.failed', 'Identifiants incorrects.');
    });

    it('ignores keys returned by the API that were not in the request', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(claudeDriverSuccessResponse(
                '{"auth.failed": "A.", "auth.injected_extra": "B."}',
            ), 200),
        ]);

        $response = $this->driver->translate($this->request);

        expect($response->translations)->toHaveKey('auth.failed')
            ->not->toHaveKey('auth.injected_extra');
    });

    it('throws TranslationProviderException when the API returns invalid JSON', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(claudeDriverSuccessResponse(
                'this is not json at all',
            ), 200),
        ]);

        expect(fn () => $this->driver->translate($this->request))
            ->toThrow(TranslationProviderException::class);
    });

    it('throws TranslationProviderException when the API returns a JSON array instead of object', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(claudeDriverSuccessResponse(
                '["auth.failed", "Identifiants incorrects."]',
            ), 200),
        ]);

        expect(fn () => $this->driver->translate($this->request))
            ->toThrow(TranslationProviderException::class);
    });

    // -------------------------------------------------------------------------
    // HTTP error codes
    // -------------------------------------------------------------------------

    it('throws ProviderAuthenticationException on HTTP 401', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'Invalid API key.']],
                401,
            ),
        ]);

        expect(fn () => $this->driver->translate($this->request))
            ->toThrow(ProviderAuthenticationException::class);
    });

    it('throws ProviderAuthenticationException on HTTP 403', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                ['type' => 'error', 'error' => ['type' => 'permission_error']],
                403,
            ),
        ]);

        expect(fn () => $this->driver->translate($this->request))
            ->toThrow(ProviderAuthenticationException::class);
    });

    it('throws ProviderRateLimitException on HTTP 429', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                ['type' => 'error', 'error' => ['type' => 'rate_limit_error']],
                429,
            ),
        ]);

        expect(fn () => $this->driver->translate($this->request))
            ->toThrow(ProviderRateLimitException::class);
    });

    it('throws TranslationProviderException on HTTP 500', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => 'Internal server error'], 500),
        ]);

        expect(fn () => $this->driver->translate($this->request))
            ->toThrow(TranslationProviderException::class);
    });

    // -------------------------------------------------------------------------
    // API key configuration
    // -------------------------------------------------------------------------

    it('reports available when API key is configured', function (): void {
        expect($this->driver->isAvailable())->toBeTrue();
    });

    it('reports unavailable when API key is null', function (): void {
        config(['translator.ai.providers.claude.api_key' => null]);
        $driver = new ClaudeDriver(new TokenEstimator, new TranslationPromptBuilder);

        expect($driver->isAvailable())->toBeFalse();
    });

    it('reports unavailable when API key is an empty string', function (): void {
        config(['translator.ai.providers.claude.api_key' => '']);
        $driver = new ClaudeDriver(new TokenEstimator, new TranslationPromptBuilder);

        expect($driver->isAvailable())->toBeFalse();
    });

    it('throws ProviderAuthenticationException when translate() is called without an API key', function (): void {
        config(['translator.ai.providers.claude.api_key' => null]);
        $driver = new ClaudeDriver(new TokenEstimator, new TranslationPromptBuilder);

        expect(fn () => $driver->translate($this->request))
            ->toThrow(ProviderAuthenticationException::class);
    });

    // -------------------------------------------------------------------------
    // estimate() — must NOT make any HTTP call
    // -------------------------------------------------------------------------

    it('estimates cost without sending any HTTP request', function (): void {
        Http::fake(); // no responses registered — any request would throw

        $estimate = $this->driver->estimate($this->request);

        expect($estimate->provider)->toBe('claude')
            ->and($estimate->keyCount)->toBe(1)
            ->and($estimate->estimatedCostUsd)->toBeGreaterThan(0.0)
            ->and($estimate->estimatedInputTokens)->toBeGreaterThan(0)
            ->and($estimate->estimatedOutputTokens)->toBeGreaterThan(0);

        Http::assertNothingSent();
    });

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    function claudeDriverSuccessResponse(
        string $textContent,
        int $inputTokens = 150,
        int $outputTokens = 48,
        string $model = 'claude-haiku-4-5-20251001',
    ): array {
        return [
            'id' => 'msg_test_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => $textContent]],
            'model' => $model,
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
            ],
        ];
    }
});
