<?php

declare(strict_types=1);

namespace Syriable\Translator\AI\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Syriable\Translator\AI\Contracts\TranslationProviderInterface;
use Syriable\Translator\AI\Estimators\TokenEstimator;
use Syriable\Translator\AI\Prompts\TranslationPromptBuilder;
use Syriable\Translator\DTOs\AI\TranslationEstimate;
use Syriable\Translator\DTOs\AI\TranslationRequest;
use Syriable\Translator\DTOs\AI\TranslationResponse;
use Syriable\Translator\Exceptions\AI\ProviderAuthenticationException;
use Syriable\Translator\Exceptions\AI\ProviderRateLimitException;
use Syriable\Translator\Exceptions\AI\TranslationProviderException;

/**
 * AI translation driver for the OpenAI ChatGPT API (GPT-4o).
 *
 * Demonstrates how to add a second provider by implementing
 * TranslationProviderInterface. Register this driver in the service provider:
 *
 * ```php
 * $manager->extend('chatgpt', fn () => app(ChatGptDriver::class));
 * ```
 *
 * Or bind it automatically by adding 'chatgpt' to the match block in
 * TranslationProviderManager::createDriver().
 *
 * @see https://platform.openai.com/docs/api-reference/chat
 */
final readonly class ChatGptDriver implements TranslationProviderInterface
{
    private const string PROVIDER_NAME = 'chatgpt';

    private const string API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        private TokenEstimator $estimator,
        private TranslationPromptBuilder $promptBuilder,
    ) {}

    // -------------------------------------------------------------------------
    // TranslationProviderInterface
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function estimate(TranslationRequest $request): TranslationEstimate
    {
        $systemPrompt = $this->promptBuilder->buildSystemPrompt($request);
        $userMessage = $this->promptBuilder->buildUserMessage($request);

        $inputTokens = $this->estimator->estimateInputTokens(
            prompt: $systemPrompt.$userMessage,
            keys: $request->keys,
            sourceLocale: $request->sourceLanguage,
        );

        $outputTokens = $this->estimator->estimateOutputTokens(
            keys: $request->keys,
            targetLocale: $request->targetLanguage,
        );

        $estimatedCost = $this->estimator->estimateCost(
            provider: self::PROVIDER_NAME,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );

        return new TranslationEstimate(
            provider: self::PROVIDER_NAME,
            model: $this->resolveModel(),
            estimatedInputTokens: $inputTokens,
            estimatedOutputTokens: $outputTokens,
            estimatedCostUsd: $estimatedCost,
            keyCount: $request->keyCount(),
            sourceCharacters: $request->totalSourceCharacters(),
        );
    }

    /** {@inheritdoc} */
    public function translate(TranslationRequest $request): TranslationResponse
    {
        $startTime = microtime(true);
        $payload = $this->buildApiPayload($request);
        $response = $this->callApi($payload);
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return $this->parseApiResponse($response, $request, $durationMs);
    }

    /** {@inheritdoc} */
    public function providerName(): string
    {
        return self::PROVIDER_NAME;
    }

    /** {@inheritdoc} */
    public function isAvailable(): bool
    {
        return filled(config('translator.ai.providers.chatgpt.api_key'));
    }

    // -------------------------------------------------------------------------
    // API Communication
    // -------------------------------------------------------------------------

    /**
     * Build the OpenAI Chat Completions API payload.
     *
     * @return array<string, mixed>
     */
    private function buildApiPayload(TranslationRequest $request): array
    {
        return [
            'model' => $this->resolveModel(),
            'max_tokens' => $this->resolveMaxTokens(),
            'temperature' => 0.1, // Low temperature for deterministic, accurate translations.
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->promptBuilder->buildSystemPrompt($request),
                ],
                [
                    'role' => 'user',
                    'content' => $this->promptBuilder->buildUserMessage($request),
                ],
            ],
        ];
    }

    /**
     * Execute the HTTP POST request to the OpenAI Chat Completions endpoint.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ProviderAuthenticationException
     * @throws ProviderRateLimitException
     * @throws TranslationProviderException
     */
    private function callApi(array $payload): array
    {
        $apiKey = $this->resolveApiKey();
        $timeout = (int) config('translator.ai.providers.chatgpt.timeout_seconds', 120);
        $retries = (int) config('translator.ai.providers.chatgpt.max_retries', 3);

        try {
            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->retry($retries, sleepMilliseconds: 1000)
                ->post(self::API_ENDPOINT, $payload);

            if ($response->status() === 401 || $response->status() === 403) {
                throw new ProviderAuthenticationException(
                    provider: self::PROVIDER_NAME,
                    message: 'Invalid or missing OpenAI API key.',
                );
            }

            if ($response->status() === 429) {
                throw new ProviderRateLimitException(
                    provider: self::PROVIDER_NAME,
                    message: 'OpenAI API rate limit exceeded.',
                );
            }

            $response->throw();

            return $response->json();
        } catch (ProviderAuthenticationException|ProviderRateLimitException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            throw new TranslationProviderException(
                provider: self::PROVIDER_NAME,
                message: 'Could not connect to the OpenAI API: '.$e->getMessage(),
                previous: $e,
            );
        } catch (RequestException $e) {
            throw new TranslationProviderException(
                provider: self::PROVIDER_NAME,
                message: 'OpenAI API request failed: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Parse the OpenAI Chat Completions response into a normalised TranslationResponse.
     *
     * @param  array<string, mixed>  $apiResponse
     *
     * @throws TranslationProviderException
     */
    private function parseApiResponse(
        array $apiResponse,
        TranslationRequest $request,
        int $durationMs,
    ): TranslationResponse {
        // OpenAI returns content in choices[0].message.content.
        $rawContent = $apiResponse['choices'][0]['message']['content'] ?? '';

        $translations = $this->extractTranslations($rawContent, $request);
        $failedKeys = array_values(
            array_diff(array_keys($request->keys), array_keys($translations)),
        );

        $usage = $apiResponse['usage'] ?? [];
        $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? 0);

        $actualCost = $this->estimator->estimateCost(
            provider: self::PROVIDER_NAME,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
        );

        return new TranslationResponse(
            provider: self::PROVIDER_NAME,
            model: $apiResponse['model'] ?? $this->resolveModel(),
            translations: $translations,
            failedKeys: $failedKeys,
            inputTokensUsed: $inputTokens,
            outputTokensUsed: $outputTokens,
            actualCostUsd: $actualCost,
            durationMs: $durationMs,
        );
    }

    /**
     * Extract and validate the JSON translation map from the model's response.
     *
     * @return array<string, string>
     *
     * @throws TranslationProviderException
     */
    private function extractTranslations(string $rawContent, TranslationRequest $request): array
    {
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', trim($rawContent));
        $cleaned = trim($cleaned ?? $rawContent);

        try {
            $decoded = json_decode($cleaned, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TranslationProviderException(
                provider: self::PROVIDER_NAME,
                message: "Failed to parse JSON from ChatGPT response: {$cleaned}",
                previous: $e,
            );
        }

        if (! is_array($decoded)) {
            throw new TranslationProviderException(
                provider: self::PROVIDER_NAME,
                message: 'ChatGPT response did not return a JSON object.',
            );
        }

        $validKeys = array_keys($request->keys);

        return array_filter(
            array_intersect_key($decoded, array_flip($validKeys)),
            static fn (mixed $value): bool => is_string($value) && filled($value),
        );
    }

    // -------------------------------------------------------------------------
    // Configuration Helpers
    // -------------------------------------------------------------------------

    private function resolveModel(): string
    {
        return (string) config('translator.ai.providers.chatgpt.model', 'gpt-4o');
    }

    private function resolveMaxTokens(): int
    {
        return (int) config('translator.ai.providers.chatgpt.max_tokens', 4096);
    }

    private function resolveApiKey(): string
    {
        $key = config('translator.ai.providers.chatgpt.api_key');

        if (blank($key)) {
            throw new ProviderAuthenticationException(
                provider: self::PROVIDER_NAME,
                message: 'OpenAI API key is not configured. Set OPENAI_API_KEY in your .env file.',
            );
        }

        return (string) $key;
    }
}
