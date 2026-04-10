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
use Throwable;

/**
 * AI translation driver for the Anthropic Claude API.
 *
 * Implements the full translation lifecycle for Claude:
 *  - Pre-execution token and cost estimation (no API call).
 *  - Prompt construction via TranslationPromptBuilder.
 *  - HTTP communication with retry handling.
 *  - Response parsing and normalisation.
 *  - Actual cost calculation from reported token usage.
 *
 * Configuration is read from `translator.ai.providers.claude.*`.
 *
 * @see https://docs.anthropic.com/en/api/messages
 */
final readonly class ClaudeDriver implements TranslationProviderInterface
{
    private const string PROVIDER_NAME = 'claude';

    private const string API_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const string API_VERSION = '2023-06-01';

    public function __construct(
        private TokenEstimator $estimator,
        private TranslationPromptBuilder $promptBuilder,
    ) {}

    // -------------------------------------------------------------------------
    // TranslationProviderInterface
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Estimates token usage and cost for the given request using character
     * count ratios. No API call is made. The estimate accounts for:
     *  - System prompt length.
     *  - User message framing.
     *  - All source key names and values.
     *  - Expected output size with locale-specific expansion.
     */
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

    /**
     * {@inheritdoc}
     *
     * Calls the Anthropic Messages API with the structured translation prompt.
     * Parses the JSON response and maps translated values back to their keys.
     * Any keys missing from the response are recorded as failed keys.
     *
     * @throws ProviderAuthenticationException When the API key is invalid or missing.
     * @throws ProviderRateLimitException When the API rate limit is exceeded.
     * @throws TranslationProviderException For any other provider-side failure.
     */
    public function translate(TranslationRequest $request): TranslationResponse
    {
        $startTime = microtime(true);

        $payload = $this->buildApiPayload($request);
        $response = $this->callApi($payload);

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        return $this->parseApiResponse($response, $request, $durationMs);
    }

    /**
     * {@inheritdoc}
     */
    public function providerName(): string
    {
        return self::PROVIDER_NAME;
    }

    /**
     * {@inheritdoc}
     *
     * Verifies the API key is present in configuration. Does not make
     * a live API call to avoid consuming quota during health checks.
     */
    public function isAvailable(): bool
    {
        return filled(config('translator.ai.providers.claude.api_key'));
    }

    // -------------------------------------------------------------------------
    // API Communication
    // -------------------------------------------------------------------------

    /**
     * Build the JSON payload for the Anthropic Messages API.
     *
     * @return array<string, mixed>
     */
    private function buildApiPayload(TranslationRequest $request): array
    {
        return [
            'model' => $this->resolveModel(),
            'max_tokens' => $this->resolveMaxTokens(),
            'system' => $this->promptBuilder->buildSystemPrompt($request),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->promptBuilder->buildUserMessage($request),
                ],
            ],
        ];
    }

    /**
     * Execute the HTTP POST request to the Anthropic Messages API.
     *
     * Retries on transient failures (network errors, 5xx responses) up to
     * the configured maximum retry count with exponential backoff.
     *
     * @param  array<string, mixed>  $payload  The API request body.
     * @return array<string, mixed> The decoded API response body.
     *
     * @throws ProviderAuthenticationException On 401/403 responses.
     * @throws ProviderRateLimitException On 429 responses.
     * @throws TranslationProviderException On other HTTP or connection failures.
     */
    private function callApi(array $payload): array
    {
        $apiKey = $this->resolveApiKey();
        $retries = (int) config('translator.ai.providers.claude.max_retries', 3);
        $timeout = (int) config('translator.ai.providers.claude.timeout_seconds', 120);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json',
            ])
                ->timeout($timeout)
                ->retry($retries, sleepMilliseconds: 1000, when: $this->shouldRetry(...))
                ->post(self::API_ENDPOINT, $payload);

            if ($response->status() === 401 || $response->status() === 403) {
                throw new ProviderAuthenticationException(
                    provider: self::PROVIDER_NAME,
                    message: 'Invalid or missing Anthropic API key.',
                );
            }

            if ($response->status() === 429) {
                throw new ProviderRateLimitException(
                    provider: self::PROVIDER_NAME,
                    message: 'Anthropic API rate limit exceeded. Retry after a moment.',
                );
            }

            $response->throw();

            return $response->json();
        } catch (ProviderAuthenticationException|ProviderRateLimitException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            throw new TranslationProviderException(
                provider: self::PROVIDER_NAME,
                message: 'Could not connect to the Anthropic API: '.$e->getMessage(),
                previous: $e,
            );
        } catch (RequestException $e) {
            throw new TranslationProviderException(
                provider: self::PROVIDER_NAME,
                message: 'Anthropic API request failed: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Determine whether a failed HTTP request should be retried.
     *
     * Retries on network-level failures and 5xx server errors.
     * Does not retry on 4xx client errors (auth, rate limit, bad request).
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            return $exception->response->serverError();
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Response Parsing
    // -------------------------------------------------------------------------

    /**
     * Parse the raw Anthropic API response into a normalised TranslationResponse.
     *
     * The API returns translated content as a JSON string within the text block.
     * This method extracts the JSON, maps translated values back to their keys,
     * and identifies any keys missing from the response.
     *
     * @param  array<string, mixed>  $apiResponse  The decoded API response.
     * @param  TranslationRequest  $request  The original request (used to validate coverage).
     * @param  int  $durationMs  Time taken for the API call.
     *
     * @throws TranslationProviderException When the response cannot be parsed.
     */
    private function parseApiResponse(
        array $apiResponse,
        TranslationRequest $request,
        int $durationMs,
    ): TranslationResponse {
        $rawContent = $apiResponse['content'][0]['text'] ?? '';

        $translations = $this->extractTranslations($rawContent, $request);
        $failedKeys = array_values(
            array_diff(array_keys($request->keys), array_keys($translations)),
        );

        $usage = $apiResponse['usage'] ?? [];
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

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
     * Extract and validate the JSON translation map from the API response text.
     *
     * The model is instructed to return a JSON object with the original keys
     * and translated values. This method strips any accidental markdown fences,
     * parses the JSON, and filters to only keys that exist in the original request.
     *
     * @param  string  $rawContent  The text block returned by the model.
     * @param  TranslationRequest  $request  The original request for key validation.
     * @return array<string, string> The parsed translation map.
     *
     * @throws TranslationProviderException When JSON cannot be parsed from the response.
     */
    private function extractTranslations(string $rawContent, TranslationRequest $request): array
    {
        // Strip any markdown code fences the model may have added despite instructions.
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', trim($rawContent));
        $cleaned = trim($cleaned ?? $rawContent);

        try {
            $decoded = json_decode($cleaned, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TranslationProviderException(
                provider: self::PROVIDER_NAME,
                message: "Failed to parse JSON from Claude response. Raw content: {$cleaned}",
                previous: $e,
            );
        }

        if (! is_array($decoded)) {
            throw new TranslationProviderException(
                provider: self::PROVIDER_NAME,
                message: 'Claude response did not return a JSON object as expected.',
            );
        }

        // Filter to only keys that were requested, cast values to strings.
        $validKeys = array_keys($request->keys);
        $translations = [];

        foreach ($decoded as $key => $value) {
            if (in_array($key, $validKeys, strict: true) && is_string($value) && filled($value)) {
                $translations[$key] = $value;
            }
        }

        return $translations;
    }

    // -------------------------------------------------------------------------
    // Configuration Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the Claude model to use from configuration.
     */
    private function resolveModel(): string
    {
        return (string) config(
            'translator.ai.providers.claude.model',
            'claude-haiku-4-5-20251001',
        );
    }

    /**
     * Resolve the maximum output tokens from configuration.
     */
    private function resolveMaxTokens(): int
    {
        return (int) config(
            'translator.ai.providers.claude.max_tokens',
            4096,
        );
    }

    /**
     * Resolve the Anthropic API key from configuration.
     *
     * @throws ProviderAuthenticationException When the key is absent or empty.
     */
    private function resolveApiKey(): string
    {
        $key = config('translator.ai.providers.claude.api_key');

        if (blank($key)) {
            throw new ProviderAuthenticationException(
                provider: self::PROVIDER_NAME,
                message: 'Anthropic API key is not configured. Set ANTHROPIC_API_KEY in your .env file.',
            );
        }

        return (string) $key;
    }
}
