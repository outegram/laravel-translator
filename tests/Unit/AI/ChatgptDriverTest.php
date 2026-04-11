<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Syriable\Translator\AI\Drivers\ChatGptDriver;
use Syriable\Translator\AI\Estimators\TokenEstimator;
use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\Exceptions\AI\ProviderAuthenticationException;
use Syriable\Translator\Exceptions\AI\ProviderRateLimitException;
use Syriable\Translator\Exceptions\AI\TranslationProviderException;
use Syriable\Translator\Models\Language;

/**
 * Http::fake() tests for the ChatGptDriver (OpenAI Chat Completions API).
 *
 * Mirrors the structure of ClaudeDriverTest to ensure both drivers follow
 * the same contract guarantees. All HTTP calls are intercepted.
 */
describe('ChatGptDriver', function (): void {

    beforeEach(function (): void {
        config([
            'translator.ai.providers.chatgpt.api_key' => 'sk-openai-test',
            'translator.ai.providers.chatgpt.model' => 'gpt-4o',
            'translator.ai.providers.chatgpt.max_tokens' => 4096,
            'translator.ai.providers.chatgpt.timeout_seconds' => 30,
            'translator.ai.providers.chatgpt.max_retries' => 1,
            'translator.ai.providers.chatgpt.input_cost_per_1k_tokens' => 0.0025,
            'translator.ai.providers.chatgpt.output_cost_per_1k_tokens' => 0.010,
            'translator.ai.translation_memory.enabled' => false,
        ]);

        Language::factory()->english()->create();
        Language::factory()->french()->create();

        $this->driver = new ChatGptDriver(
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

    it('translates a single key successfully via the OpenAI Chat Completions API', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(chatgptDriverSuccessResponse(
                '{"auth.failed": "Identifiants incorrects."}',
            ), 200),
        ]);

        $response = $this->driver->translate($this->request);

        expect($response->translations)->toHaveKey('auth.failed', 'Identifiants incorrects.')
            ->and($response->failedKeys)->toBeEmpty()
            ->and($response->provider)->toBe('chatgpt')
            ->and($response->inputTokensUsed)->toBe(180)
            ->and($response->outputTokensUsed)->toBe(55);
    });

    it('translates multiple keys in a single request', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(chatgptDriverSuccessResponse(
                '{"auth.failed": "Identifiants incorrects.", "auth.throttle": "Trop de tentatives."}',
                promptTokens: 350,
                completionTokens: 85,
            ), 200),
        ]);

        $response = $this->driver->translate($this->multiRequest);

        expect($response->translations)
            ->toHaveKey('auth.failed', 'Identifiants incorrects.')
            ->toHaveKey('auth.throttle', 'Trop de tentatives.')
            ->and($response->failedKeys)->toBeEmpty();
    });

    it('records keys missing from the API response as failed keys', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(chatgptDriverSuccessResponse(
                '{"auth.failed": "Identifiants incorrects."}',
            ), 200),
        ]);

        $response = $this->driver->translate($this->multiRequest);

        expect($response->failedKeys)->toContain('auth.throttle')
            ->and($response->failedKeys)->toHaveCount(1);
    });

    it('uses the model name returned by the API', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(array_merge(
                chatgptDriverSuccessResponse('{"auth.failed": "Test."}'),
                ['model' => 'gpt-4o-2024-08-06'],
            ), 200),
        ]);

        $response = $this->driver->translate($this->request);

        expect($response->model)->toBe('gpt-4o-2024-08-06');
    });

    it('computes actual cost from prompt + completion token counts', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(chatgptDriverSuccessResponse(
                '{"auth.failed": "Identifiants incorrects."}',
                promptTokens: 1000,
                completionTokens: 500,
            ), 200),
        ]);

        $response = $this->driver->translate($this->request);

        // 1000 * 0.0025/1000 + 500 * 0.010/1000 = 0.0025 + 0.005 = 0.0075
        expect($response->actualCostUsd)->toBe(0.0075);
    });

    // -------------------------------------------------------------------------
    // Response parsing edge cases
    // -------------------------------------------------------------------------

    it('strips markdown JSON code fences from the response', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(chatgptDriverSuccessResponse(
                "```json\n{\"auth.failed\": \"Identifiants incorrects.\"}\n```",
            ), 200),
        ]);

        $response = $this->driver->translate($this->request);

        expect($response->translations)->toHaveKey('auth.failed', 'Identifiants incorrects.');
    });

    it('ignores extra keys not in the original request', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(chatgptDriverSuccessResponse(
                '{"auth.failed": "A.", "injected_key": "B."}',
            ), 200),
        ]);

        $response = $this->driver->translate($this->request);

        expect($response->translations)->not->toHaveKey('injected_key');
    });

    it('throws TranslationProviderException when the API returns invalid JSON', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(chatgptDriverSuccessResponse('not valid json'), 200),
        ]);

        expect(fn () => $this->driver->translate($this->request))
            ->toThrow(TranslationProviderException::class);
    });

    it('throws TranslationProviderException when the API returns a JSON array', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(chatgptDriverSuccessResponse('["key", "value"]'), 200),
        ]);

        expect(fn () => $this->driver->translate($this->request))
            ->toThrow(TranslationProviderException::class);
    });

    // -------------------------------------------------------------------------
    // HTTP error codes
    // -------------------------------------------------------------------------

    it('throws ProviderAuthenticationException on HTTP 401', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(
                ['error' => ['message' => 'Incorrect API key.', 'type' => 'invalid_request_error']],
                401,
            ),
        ]);

        expect(fn () => $this->driver->translate($this->request))
            ->toThrow(ProviderAuthenticationException::class);
    });

    it('throws ProviderAuthenticationException on HTTP 403', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['type' => 'permission_denied']], 403),
        ]);

        expect(fn () => $this->driver->translate($this->request))
            ->toThrow(ProviderAuthenticationException::class);
    });

    it('throws ProviderRateLimitException on HTTP 429', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(
                ['error' => ['message' => 'Rate limit reached.', 'type' => 'requests']],
                429,
            ),
        ]);

        expect(fn () => $this->driver->translate($this->request))
            ->toThrow(ProviderRateLimitException::class);
    });

    it('throws TranslationProviderException on HTTP 500', function (): void {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => 'Internal server error.'], 500),
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
        config(['translator.ai.providers.chatgpt.api_key' => null]);
        $driver = new ChatGptDriver(new TokenEstimator, new TranslationPromptBuilder);

        expect($driver->isAvailable())->toBeFalse();
    });

    it('throws ProviderAuthenticationException when translate() is called without an API key', function (): void {
        config(['translator.ai.providers.chatgpt.api_key' => null]);
        $driver = new ChatGptDriver(new TokenEstimator, new TranslationPromptBuilder);

        expect(fn () => $driver->translate($this->request))
            ->toThrow(ProviderAuthenticationException::class);
    });

    // -------------------------------------------------------------------------
    // estimate() — must NOT make any HTTP call
    // -------------------------------------------------------------------------

    it('estimates cost without sending any HTTP request', function (): void {
        Http::fake();

        $estimate = $this->driver->estimate($this->request);

        expect($estimate->provider)->toBe('chatgpt')
            ->and($estimate->keyCount)->toBe(1)
            ->and($estimate->estimatedCostUsd)->toBeGreaterThan(0.0);

        Http::assertNothingSent();
    });

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an OpenAI Chat Completions API response body.
     *
     * @return array<string, mixed>
     */
    function chatgptDriverSuccessResponse(
        string $content,
        int $promptTokens = 180,
        int $completionTokens = 55,
        string $model = 'gpt-4o',
    ): array {
        return [
            'id' => 'chatcmpl-'.uniqid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ],
        ];
    }
});
